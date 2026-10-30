<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import DocumentoExigido from '../../Components/Inscricao/DocumentoExigido.vue';
import EscolhasDaInscricao from '../../Components/Inscricao/EscolhasDaInscricao.vue';
import IndicadorDePassos from '../../Components/Inscricao/IndicadorDePassos.vue';
import PassoDaSecao from '../../Components/Inscricao/PassoDaSecao.vue';
import RevisaoDaInscricao from '../../Components/Inscricao/RevisaoDaInscricao.vue';
import { useSituacaoDosDocumentos } from '../../Components/Inscricao/useSituacaoDosDocumentos';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { DocumentoDaInscricao, PassoDaInscricao, PortalInscricaoPassosProps, SecaoDaInscricao } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<PortalInscricaoPassosProps>();

// Caminho das rotas portal.inscricoes.* (o front não tem helper de rotas nomeadas).
const BASE = `/portal/inscricoes/${props.inscricao.id}`;
const ORDEM_DAS_ETAPAS = ['inscricao', 'validacao_cotas', 'habilitacao'];
const ERRO_SEM_ARQUIVO = 'Anexe este documento para continuar.';
const AVISO_FICOU_NOS_DOCUMENTOS = 'Ainda falta algum documento obrigatório. Confira a lista e anexe o que falta para continuar.';
const ID_TITULO_DO_PASSO = 'passo-titulo';

// O Inertia faz uma requisição comum por vez: a nova cancela a que ainda está em andamento (um envio de arquivo, por exemplo)
// sem avisar. Enquanto houver uma, os controles que disparam outra ficam desabilitados. A consulta periódica usa
// fetch e as requisições assíncronas não interrompem ninguém, então nenhuma das duas conta.
const requisicoesEmAndamento = ref<number>(0);
const ocupado = computed<boolean>(() => requisicoesEmAndamento.value > 0);
const pararDeOuvirInicio = router.on('start', (evento) => {
    if (!evento.detail.visit.async) {
        requisicoesEmAndamento.value += 1;
    }
});
const pararDeOuvirFim = router.on('finish', (evento) => {
    if (!evento.detail.visit.async) {
        requisicoesEmAndamento.value = Math.max(0, requisicoesEmAndamento.value - 1);
    }
});
onBeforeUnmount(() => {
    pararDeOuvirInicio();
    pararDeOuvirFim();
});

const { situacoes, pendencias, podeFinalizar } = useSituacaoDosDocumentos(props);

// Passo aberto, o anterior (para "Voltar") e o seguinte.
const enderecoDoPasso = (id: string): string => `${BASE}?passo=${encodeURIComponent(id)}`;
const indiceAtual = computed<number>(() => Math.max(0, props.passos.findIndex((passo) => passo.id === props.passoAtual)));
const passo = computed<PassoDaInscricao | undefined>(() => props.passos[indiceAtual.value]);
const voltarPara = computed<string | null>(() => {
    const anterior = props.passos[indiceAtual.value - 1];
    return props.podeEditar && anterior ? enderecoDoPasso(anterior.id) : null;
});
const secaoAtual = computed<SecaoDaInscricao | undefined>(() =>
    props.passoAtual.startsWith('secao-') ? props.secoes.find((secao) => `secao-${secao.id}` === props.passoAtual) : undefined,
);

// "Avançar" nos documentos confere na tela os obrigatórios sem arquivo; o erro some de cada um quando o arquivo chega.
const documentosConferidos = ref<boolean>(false);
// Aviso de quando o servidor manteve a inscrição nos documentos (algo mudou desde que a tela abriu).
const avisoDosDocumentos = ref<string | null>(null);

// Os passos trocam sem recriar a página (links com preserve-state e o redirecionamento depois de salvar): a cada troca,
// o foco vai para o título do passo novo, e quem usa leitor de tela ouve onde está.
watch(
    () => props.passoAtual,
    () => {
        documentosConferidos.value = false;
        avisoDosDocumentos.value = null;
        void nextTick(() => document.getElementById(ID_TITULO_DO_PASSO)?.focus());
    },
);

// Escolhas do rascunho: "Avançar" salva e o servidor leva ao passo seguinte.
const formulario = useForm<{ cadastro: string; categoria: number | null; cotas: string[] }>({
    cadastro: props.inscricao.cadastroChave,
    categoria: props.inscricao.categoriaId,
    cotas: [...props.inscricao.cotas],
});

const salvarEscolhas = (): void => {
    formulario.put(BASE, {
        preserveScroll: true,
        onSuccess: () => formulario.defaults(),
        onError: () => focarPrimeiroErro('#formulario-escolhas'),
    });
};

// Documentos agrupados por etapa, na ordem do edital dentro de cada uma.
const posicaoDaEtapa = (etapa: string): number => {
    const posicao = ORDEM_DAS_ETAPAS.indexOf(etapa);
    return posicao === -1 ? ORDEM_DAS_ETAPAS.length : posicao;
};

interface GrupoDeEtapa {
    etapa: string;
    rotulo: string;
    documentos: DocumentoDaInscricao[];
}

const etapas = computed<GrupoDeEtapa[]>(() => {
    const grupos = new Map<string, GrupoDeEtapa>();
    for (const documento of props.documentos) {
        const grupo = grupos.get(documento.etapa) ?? { etapa: documento.etapa, rotulo: documento.etapaRotulo, documentos: [] };
        grupo.documentos.push(documento);
        grupos.set(documento.etapa, grupo);
    }
    return [...grupos.values()].sort((a, b) => posicaoDaEtapa(a.etapa) - posicaoDaEtapa(b.etapa));
});

const semArquivo = (documento: DocumentoDaInscricao): boolean => documento.obrigatorio && !situacoes.value[documento.id];
const erroDoDocumento = (documento: DocumentoDaInscricao): string | undefined =>
    documentosConferidos.value && semArquivo(documento) ? ERRO_SEM_ARQUIVO : undefined;

const avancarDosDocumentos = (): void => {
    documentosConferidos.value = true;
    avisoDosDocumentos.value = null;
    if (props.documentos.some(semArquivo)) {
        focarPrimeiroErro('#passo-documentos');
        return;
    }

    // O servidor confere de novo: com algo faltando, abre o primeiro passo incompleto.
    const seguinte = props.passos[indiceAtual.value + 1];
    if (seguinte) {
        router.get(
            enderecoDoPasso(seguinte.id),
            {},
            {
                preserveState: true,
                onSuccess: () => {
                    if (props.passoAtual === 'documentos') {
                        avisoDosDocumentos.value = AVISO_FICOU_NOS_DOCUMENTOS;
                        void nextTick(() => document.getElementById('passo-documentos-aviso')?.focus());
                    }
                },
            },
        );
    }
};

const classeBotao =
    'pressionavel inline-flex min-h-12 items-center justify-center rounded-pill px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] uppercase no-underline disabled:cursor-not-allowed disabled:opacity-70';
const classePrimario = `${classeBotao} ml-auto bg-acao text-sobre-acao hover:bg-acao-escuro`;
const classeSecundario = `${classeBotao} border border-tinta-suave/70 bg-superficie text-home-tinta hover:border-acao hover:text-acao`;
const classeRodape = 'flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-6';
</script>

<template>
    <div>
        <Head :title="`Inscrição — ${edital.titulo}`" />

        <FaixaPortal aria-labelledby="inscricao-titulo">
            <div class="flex flex-col gap-3">
                <!-- Caminho da rota portal.editais.inscricao (o front não tem helper de rotas nomeadas). -->
                <Link
                    :href="`/portal/editais/${edital.slug}/inscricao`"
                    class="inline-flex min-h-11 items-center gap-2 self-start text-[0.8125rem] font-semibold tracking-[0.12em] text-white/85 uppercase no-underline hover:text-white"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    Voltar às inscrições do edital
                </Link>
                <h1 id="inscricao-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Inscrição
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    <strong class="font-semibold text-white">{{ edital.titulo }}</strong> · {{ edital.categoria }}<br />
                    <template v-if="edital.periodo">Inscrições de {{ edital.periodo }}.<br /></template>
                    {{ inscricao.situacaoRotulo }}<template v-if="inscricao.numero"> · nº {{ inscricao.numero }}</template>
                </p>
            </div>
            <template v-if="podeEditar" #abaixo>
                <IndicadorDePassos :passos="passos" :atual="passoAtual" :base="BASE" />
            </template>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <div
                v-if="perfilIncompleto"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-[16px] border border-aviso-texto/40 bg-aviso-fundo px-5 py-4 text-aviso-texto"
            >
                <p class="font-semibold">Complete o seu perfil no cadastro de Pessoa Física para finalizar a inscrição.</p>
                <!-- Caminho da rota cadastro (o front não tem helper de rotas nomeadas). -->
                <Link href="/cadastro" class="inline-flex min-h-11 items-center font-bold text-aviso-texto underline">Ir para o cadastro</Link>
            </div>

            <SecaoCadastro v-if="passo" :id="ID_TITULO_DO_PASSO" :key="passoAtual" focavel :numero="indiceAtual + 1" :titulo="passo.rotulo">
                <template #icone>
                    <svg v-if="passoAtual === 'escolhas'" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12" />
                        <circle cx="16" cy="6" r="2" />
                        <circle cx="10" cy="12" r="2" />
                        <circle cx="18" cy="18" r="2" />
                    </svg>
                    <svg v-else-if="secaoAtual" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 6h11M9 12h11M9 18h11" />
                        <path d="M4 6h.01M4 12h.01M4 18h.01" />
                    </svg>
                    <svg v-else-if="passoAtual === 'documentos'" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                        <path d="M14 3v5h5M9 13h6M9 17h6" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 21V4M5 4h11l-2 4 2 4H5" />
                    </svg>
                </template>

                <form v-if="passoAtual === 'escolhas'" id="formulario-escolhas" novalidate class="flex flex-col gap-6" @submit.prevent="salvarEscolhas">
                    <AvisoCamposObrigatorios />
                    <p class="max-w-2xl text-[0.875rem] leading-[1.5] text-tinta-suave">
                        Trocar o cadastro, a categoria ou as cotas pode mudar as perguntas e os documentos exigidos. As respostas e os arquivos que deixarem de valer são apagados.
                    </p>
                    <EscolhasDaInscricao
                        v-model:cadastro="formulario.cadastro"
                        v-model:categoria="formulario.categoria"
                        v-model:cotas-escolhidas="formulario.cotas"
                        :cadastros="cadastros"
                        :categorias="categorias"
                        :cotas="cotas"
                        :veda-negro-indigena="vedaNegroIndigena"
                        :erros="formulario.errors"
                        :cadastro-atual="inscricao.cadastroChave"
                        @limpar-erro="(campo) => formulario.clearErrors(campo)"
                    />
                    <div :class="classeRodape">
                        <button type="submit" :disabled="formulario.processing || ocupado" :class="classePrimario">
                            {{ formulario.processing ? 'Salvando…' : 'Avançar' }}
                        </button>
                    </div>
                </form>

                <PassoDaSecao
                    v-else-if="secaoAtual"
                    :secao="secaoAtual"
                    :secoes="secoes"
                    :respostas="respostas"
                    :inscricao-id="inscricao.id"
                    :voltar-para="voltarPara"
                    :ocupado="ocupado"
                />

                <div v-else-if="passoAtual === 'documentos'" id="passo-documentos" class="flex flex-col gap-6">
                    <p
                        v-if="avisoDosDocumentos"
                        id="passo-documentos-aviso"
                        role="alert"
                        tabindex="-1"
                        class="rounded-[12px] border border-invalido-texto/40 bg-invalido-fundo px-4 py-3 font-semibold text-invalido-texto"
                    >
                        {{ avisoDosDocumentos }}
                    </p>
                    <section v-for="grupo in etapas" :key="grupo.etapa" :aria-labelledby="`etapa-${grupo.etapa}-titulo`" class="flex flex-col gap-4">
                        <h3 :id="`etapa-${grupo.etapa}-titulo`" class="text-[1.0625rem] font-bold">{{ grupo.rotulo }}</h3>
                        <ul class="flex flex-col gap-4">
                            <li v-for="documento in grupo.documentos" :key="documento.id">
                                <DocumentoExigido
                                    :documento="documento"
                                    :inscricao-id="inscricao.id"
                                    :situacao="situacoes[documento.id] ?? null"
                                    :pode-editar="podeEditar"
                                    :ocupado="ocupado"
                                    :erro="erroDoDocumento(documento)"
                                />
                            </li>
                        </ul>
                    </section>
                    <p v-if="etapas.length === 0" class="leading-[1.6] text-tinta-suave">Nenhum documento é exigido para as escolhas desta inscrição.</p>

                    <div :class="classeRodape">
                        <Link v-if="voltarPara" :href="voltarPara" preserve-state :class="classeSecundario">Voltar</Link>
                        <button type="button" :disabled="ocupado" :class="classePrimario" @click="avancarDosDocumentos">Avançar</button>
                    </div>
                </div>

                <RevisaoDaInscricao
                    v-else
                    :inscricao="inscricao"
                    :cotas="cotas"
                    :secoes="secoes"
                    :respostas="respostas"
                    :documentos="documentos"
                    :situacoes="situacoes"
                    :pendencias="pendencias"
                    :pode-finalizar="podeFinalizar"
                    :pode-editar="podeEditar"
                    :pode-reabrir="podeReabrir"
                    :resultado="resultado"
                    :ocupado="ocupado"
                    :voltar-para="voltarPara"
                />
            </SecaoCadastro>
        </div>
    </div>
</template>
