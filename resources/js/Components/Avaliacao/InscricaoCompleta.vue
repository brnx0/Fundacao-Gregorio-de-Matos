<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { textoDaResposta } from '../Inscricao/questionario';
import AbasFormulario from '../Retaguarda/AbasFormulario.vue';
import SecaoCadastro from '../SecaoCadastro.vue';
import VisualizadorDeArquivo from './VisualizadorDeArquivo.vue';
import type { AbaFormulario, DocumentoDaAvaliacao, InscricaoCompletaProps } from '../../types';

// A inscrição só para leitura, em abas (uma por parte, para o avaliador ler uma coisa de cada vez), sem o parecer da
// IA nem as marcações do gestor. A última aba é da página (as notas, no slot com o id dela). Os arquivos abrem pela rota
// do avaliador, na própria tela (VisualizadorDeArquivo).
const props = defineProps<
    InscricaoCompletaProps & {
        /** Aba depois das da inscrição, com o conteúdo no slot de mesmo id. */
        abaFinal?: { id: string; rotulo: string };
    }
>();

// Só as perguntas visíveis pela condição; seção sem nenhuma não aparece.
const secoesVisiveis = computed(() => {
    const visiveis = new Set(props.perguntasVisiveis);
    return props.secoes
        .map((secao) => ({ ...secao, perguntas: secao.perguntas.filter((pergunta) => visiveis.has(pergunta.id)) }))
        .filter((secao) => secao.perguntas.length > 0);
});

const totalDePerguntas = computed<number>(() => secoesVisiveis.value.reduce((total, secao) => total + secao.perguntas.length, 0));
const enviados = computed<number>(() => props.documentos.filter((documento) => documento.enviado !== null).length);

// Sem campos de formulário: os marcadores de erro das abas não aparecem aqui.
const abas = computed<AbaFormulario[]>(() => [
    { id: 'escolhas', rotulo: 'Escolhas e perfil', campos: [] },
    { id: 'documentos', rotulo: `Documentos (${props.documentos.length})`, campos: [] },
    { id: 'questionario', rotulo: `Questionário (${totalDePerguntas.value})`, campos: [] },
    ...(props.abaFinal ? [{ ...props.abaFinal, campos: [] }] : []),
]);

const ativa = ref<string>('escolhas');
const ID_ABAS = 'inscricao-abas';

const indiceAtivo = computed<number>(() => abas.value.findIndex((aba) => aba.id === ativa.value));
const anterior = computed<AbaFormulario | null>(() => abas.value[indiceAtivo.value - 1] ?? null);
const proxima = computed<AbaFormulario | null>(() => abas.value[indiceAtivo.value + 1] ?? null);

// "Anterior" e "Próxima": troca de aba, volta ao topo das abas e leva o foco à aba nova (o leitor de tela a anuncia).
const irPara = (aba: AbaFormulario): void => {
    ativa.value = aba.id;
    void nextTick(() => {
        document.getElementById(ID_ABAS)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        document.getElementById(`inscricao-aba-${aba.id}`)?.focus({ preventScroll: true });
    });
};

const visualizador = ref<InstanceType<typeof VisualizadorDeArquivo> | null>(null);
const visualizar = (documento: DocumentoDaAvaliacao): void => {
    if (documento.enviado) visualizador.value?.abrir(documento.enviado, documento.nome);
};

const ICONES = {
    escolhas: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
    documentos: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    questionario:
        'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM12 11h4M12 16h4M8 11h.01M8 16h.01',
};

const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
const classeNavegacao = 'pressionavel inline-flex min-h-11 items-center gap-2 rounded-pill border px-5 text-[0.875rem] font-bold';
const classeAnterior = `${classeNavegacao} border-tinta-suave/60 bg-superficie text-home-tinta hover:border-acao hover:text-acao`;
const classeProxima = `${classeNavegacao} border-acao bg-acao text-sobre-acao hover:bg-acao-escuro`;
</script>

<template>
    <div class="flex flex-col gap-6">
        <section aria-label="Resumo da inscrição" class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-8">
            <dl class="grid gap-x-6 gap-y-4 text-[0.9375rem] sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt :class="classeTermo">Número</dt>
                    <dd class="font-semibold tabular-nums">{{ inscricao.numero }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Edital</dt>
                    <dd class="font-semibold break-words">{{ inscricao.edital }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Chamada</dt>
                    <dd class="font-semibold break-words">{{ inscricao.chamada ?? 'Sem chamada' }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Proponente</dt>
                    <dd class="font-semibold break-words">{{ inscricao.proponente }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">{{ inscricao.cadastro === 'Pessoa física' ? 'CPF' : 'CNPJ' }}</dt>
                    <dd class="font-semibold tabular-nums">{{ inscricao.documento }}</dd>
                </div>
            </dl>
        </section>

        <div :id="ID_ABAS" class="scroll-mt-4">
            <AbasFormulario id="inscricao" v-model="ativa" :abas="abas" :erros="{}" rotulo="Partes da inscrição">
                <template #escolhas>
                    <SecaoCadastro id="secao-escolhas" :numero="1" titulo="Escolhas e perfil">
                        <template #icone>
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="ICONES.escolhas" />
                            </svg>
                        </template>

                        <dl class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <dt class="text-[0.9375rem] font-semibold">Cadastro</dt>
                                <dd :class="classeLeitura">{{ inscricao.cadastro }}</dd>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <dt class="text-[0.9375rem] font-semibold">Categoria</dt>
                                <dd :class="[classeLeitura, inscricao.categoria === null ? 'text-tinta-suave' : 'break-words']">{{ inscricao.categoria ?? 'Sem categoria' }}</dd>
                            </div>
                            <div class="flex flex-col gap-1.5 md:col-span-2">
                                <dt class="text-[0.9375rem] font-semibold">Cotas</dt>
                                <dd :class="[classeLeitura, inscricao.cotas.length === 0 ? 'text-tinta-suave' : 'break-words']">
                                    {{ inscricao.cotas.length > 0 ? inscricao.cotas.join(', ') : 'Sem cota' }}
                                </dd>
                            </div>
                            <div v-for="linha in perfil" :key="linha.rotulo" class="flex flex-col gap-1.5">
                                <dt class="text-[0.9375rem] font-semibold">{{ linha.rotulo }}</dt>
                                <dd :class="[classeLeitura, linha.valor === null ? 'text-tinta-suave' : 'break-words']">{{ linha.valor ?? 'Não informado' }}</dd>
                            </div>
                        </dl>
                    </SecaoCadastro>
                </template>

                <template #documentos>
                    <SecaoCadastro id="secao-documentos" :numero="2" titulo="Documentos">
                        <template #icone>
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="ICONES.documentos" />
                            </svg>
                        </template>

                        <p v-if="documentos.length === 0" class="text-tinta-suave">Nenhum documento é exigido para as escolhas desta inscrição.</p>
                        <template v-else>
                            <p class="mb-4 text-[0.9375rem] text-tinta-suave">
                                {{ enviados }} de {{ documentos.length }} {{ documentos.length === 1 ? 'documento enviado' : 'documentos enviados' }}. Clique em
                                “Visualizar” para ver o arquivo aqui mesmo.
                            </p>

                            <!-- Um documento por linha: a miniatura (imagem) ou o ícone do PDF, o nome e o "Visualizar". -->
                            <ul class="divide-y divide-hairline overflow-hidden rounded-[16px] border border-hairline">
                                <li v-for="documento in documentos" :key="documento.id" class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4 md:px-5">
                                    <button
                                        v-if="documento.enviado"
                                        type="button"
                                        class="pressionavel inline-flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-[12px] border border-hairline bg-nevoa text-acao hover:border-acao"
                                        :aria-label="`Visualizar ${documento.nome}`"
                                        @click="visualizar(documento)"
                                    >
                                        <img v-if="documento.enviado.tipo === 'imagem'" :src="documento.enviado.url" alt="" loading="lazy" class="size-full object-cover" />
                                        <span v-else class="flex flex-col items-center gap-0.5" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                                <path d="M14 3v5h5" />
                                            </svg>
                                            <span class="text-[0.625rem] font-bold tracking-[0.08em] uppercase">{{ documento.enviado.tipo === 'pdf' ? 'PDF' : 'Arquivo' }}</span>
                                        </span>
                                    </button>
                                    <span
                                        v-else
                                        aria-hidden="true"
                                        class="inline-flex size-16 shrink-0 items-center justify-center rounded-[12px] border border-dashed border-tinta-suave/50 text-tinta-suave"
                                    >
                                        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                            <path d="M14 3v5h5M9.5 12.5l5 5M14.5 12.5l-5 5" />
                                        </svg>
                                    </span>

                                    <div class="min-w-0 flex-1 basis-48">
                                        <h3 class="font-bold break-words">{{ documento.nome }}</h3>
                                        <p class="text-[0.8125rem] text-tinta-suave">{{ documento.etapaRotulo }} · {{ documento.obrigatorio ? 'obrigatório' : 'opcional' }}</p>
                                        <p v-if="documento.enviado" class="truncate text-[0.875rem] text-tinta-suave" :title="documento.enviado.nome">{{ documento.enviado.nome }}</p>
                                    </div>

                                    <button
                                        v-if="documento.enviado"
                                        type="button"
                                        class="pressionavel inline-flex min-h-11 items-center gap-2 rounded-[12px] bg-acao px-4 text-[0.875rem] font-bold text-sobre-acao hover:bg-acao-escuro"
                                        @click="visualizar(documento)"
                                    >
                                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                        Visualizar<span class="sr-only"> {{ documento.nome }}</span>
                                    </button>
                                    <span v-else class="inline-flex items-center rounded-pill bg-parchment px-3 py-1 text-[0.8125rem] font-semibold text-tinta-suave">Não enviado</span>
                                </li>
                            </ul>
                        </template>
                    </SecaoCadastro>
                </template>

                <template #questionario>
                    <SecaoCadastro id="secao-questionario" :numero="3" titulo="Questionário">
                        <template #icone>
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="ICONES.questionario" />
                            </svg>
                        </template>

                        <p v-if="secoesVisiveis.length === 0" class="text-tinta-suave">O edital não tem perguntas para esta inscrição.</p>
                        <div v-else class="flex flex-col gap-8">
                            <section v-for="secao in secoesVisiveis" :key="secao.id" :aria-labelledby="`questionario-secao-${secao.id}`" class="flex flex-col gap-4">
                                <h3 :id="`questionario-secao-${secao.id}`" class="text-[1.0625rem] font-bold">{{ secao.titulo }}</h3>
                                <ul class="flex flex-col gap-4">
                                    <li v-for="pergunta in secao.perguntas" :key="pergunta.id" class="rounded-[16px] border border-hairline p-5">
                                        <dl class="flex flex-col gap-1.5">
                                            <dt class="font-semibold break-words">{{ pergunta.enunciado }}</dt>
                                            <dd v-if="textoDaResposta(pergunta, respostas[pergunta.id])" class="break-words whitespace-pre-line">
                                                {{ textoDaResposta(pergunta, respostas[pergunta.id]) }}
                                            </dd>
                                            <dd v-else class="text-tinta-suave italic">Sem resposta</dd>
                                        </dl>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    </SecaoCadastro>
                </template>

                <template v-if="abaFinal" #[abaFinal.id]>
                    <slot :name="abaFinal.id" />
                </template>
            </AbasFormulario>

            <!-- A próxima parte sem voltar ao topo: o avaliador lê a inscrição na ordem e termina nas notas. -->
            <nav aria-label="Navegar entre as partes da inscrição" class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <button v-if="anterior" type="button" :class="classeAnterior" @click="irPara(anterior)">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    {{ anterior.rotulo }}
                </button>
                <span v-else />
                <button v-if="proxima" type="button" :class="classeProxima" @click="irPara(proxima)">
                    Próxima: {{ proxima.rotulo }}
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </button>
            </nav>
        </div>

        <VisualizadorDeArquivo ref="visualizador" />
    </div>
</template>
