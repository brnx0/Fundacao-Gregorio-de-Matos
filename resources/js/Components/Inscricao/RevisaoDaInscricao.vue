<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type {
    CotaDaInscricao,
    DocumentoDaInscricao,
    PortalInscricaoPassosProps,
    RespostaDaInscricao,
    ResultadoDaHomologacao as Resultado,
    SecaoDaInscricao,
    SituacaoConferencia,
    SituacaoDoDocumento,
} from '../../types';
import { perguntasVisiveis, textoDaResposta } from './questionario';
import ResultadoDaHomologacao from './ResultadoDaHomologacao.vue';

const props = defineProps<{
    inscricao: PortalInscricaoPassosProps['inscricao'];
    cotas: CotaDaInscricao[];
    secoes: SecaoDaInscricao[];
    respostas: Record<number, RespostaDaInscricao>;
    documentos: DocumentoDaInscricao[];
    /** Situação de cada documento, atualizada pela consulta periódica; nulo = nada enviado. */
    situacoes: Record<number, SituacaoDoDocumento | null>;
    pendencias: string[];
    podeFinalizar: boolean;
    podeEditar: boolean;
    podeReabrir: boolean;
    /** Resultado da homologação; nulo no rascunho. */
    resultado: Resultado | null;
    /** Outra requisição da tela em andamento (ela seria cancelada). */
    ocupado: boolean;
    /** Endereço do passo anterior; nulo na leitura. */
    voltarPara: string | null;
}>();

// Caminho das rotas portal.inscricoes.* (o front não tem helper de rotas nomeadas).
const BASE = `/portal/inscricoes/${props.inscricao.id}`;

const rotulosDasCotas = computed<string[]>(() => props.cotas.filter((cota) => props.inscricao.cotas.includes(cota.valor)).map((cota) => cota.rotulo));

// Data e hora do envio, separadas para a frase ("12/11/2026 15:30").
const envio = computed(() => {
    const [data, hora] = (props.inscricao.finalizadaEm ?? '').split(' ');
    return { data, hora };
});

// Só as perguntas visíveis com as respostas gravadas; seção sem nenhuma visível não aparece (não é passo).
const secoesNaRevisao = computed(() => {
    const visiveis = perguntasVisiveis(props.secoes, props.respostas);
    return props.secoes
        .map((secao) => ({ ...secao, perguntas: secao.perguntas.filter((pergunta) => visiveis.has(pergunta.id)) }))
        .filter((secao) => secao.perguntas.length > 0);
});

const ROTULOS_DA_SITUACAO: Record<SituacaoConferencia, string> = {
    enviado: 'Recebido',
    conferindo: 'Conferindo…',
    aprovado: 'Conferido',
    reprovado: 'Recusado',
    nao_conferido: 'Não conferido',
};

const situacaoDoDocumento = (documento: DocumentoDaInscricao): string => {
    const situacao = props.situacoes[documento.id];
    if (!situacao) {
        return documento.obrigatorio ? 'Não enviado (obrigatório)' : 'Não enviado';
    }
    return ROTULOS_DA_SITUACAO[situacao.situacao];
};

// Depois de finalizar ou reabrir, o botão que a pessoa usou some: o foco vai para o conteúdo, onde a mensagem aparece.
const focarConteudo = (): void => {
    void nextTick(() => document.getElementById('conteudo')?.focus());
};

const finalizando = ref<boolean>(false);
const finalizar = (): void => {
    router.post(
        `${BASE}/finalizar`,
        {},
        {
            onStart: () => {
                finalizando.value = true;
            },
            onSuccess: focarConteudo,
            onFinish: () => {
                finalizando.value = false;
            },
        },
    );
};

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogoReabrir = ref<HTMLDialogElement | null>(null);
const reabrindo = ref<boolean>(false);
const abrirReabrir = (): void => dialogoReabrir.value?.showModal();
const fecharReabrir = (): void => dialogoReabrir.value?.close();

const reabrir = (): void => {
    router.post(
        `${BASE}/reabrir`,
        {},
        {
            onStart: () => {
                reabrindo.value = true;
            },
            // Fechar antes de focar: ao fechar, o diálogo devolve o foco ao botão que abriu, que já saiu da tela.
            onSuccess: () => {
                fecharReabrir();
                focarConteudo();
            },
            onFinish: () => {
                reabrindo.value = false;
                fecharReabrir();
            },
        },
    );
};

const classeBotao =
    'pressionavel inline-flex min-h-12 items-center justify-center rounded-pill px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] uppercase no-underline disabled:cursor-not-allowed disabled:opacity-70';
const classePrimario = `${classeBotao} bg-acao text-sobre-acao hover:bg-acao-escuro`;
const classeSecundario = `${classeBotao} border border-tinta-suave/70 bg-superficie text-home-tinta hover:border-acao hover:text-acao`;
const classeTitulo = 'text-[1.0625rem] font-bold';
const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
</script>

<template>
    <div class="flex flex-col gap-8">
        <section aria-labelledby="revisao-escolhas" class="flex flex-col gap-3">
            <h3 id="revisao-escolhas" :class="classeTitulo">Escolhas</h3>
            <dl class="grid gap-x-8 gap-y-3 md:grid-cols-3">
                <div>
                    <dt :class="classeTermo">Cadastro</dt>
                    <dd class="break-words">{{ inscricao.cadastro }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Categoria</dt>
                    <dd class="break-words">{{ inscricao.categoria ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Cotas</dt>
                    <dd>{{ rotulosDasCotas.length > 0 ? rotulosDasCotas.join(', ') : 'Sem cota' }}</dd>
                </div>
            </dl>
        </section>

        <section v-for="secao in secoesNaRevisao" :key="secao.id" :aria-labelledby="`revisao-secao-${secao.id}`" class="flex flex-col gap-3 border-t border-hairline pt-6">
            <h3 :id="`revisao-secao-${secao.id}`" :class="classeTitulo">{{ secao.titulo }}</h3>
            <dl class="flex flex-col gap-4">
                <div v-for="pergunta in secao.perguntas" :key="pergunta.id">
                    <dt :class="classeTermo">{{ pergunta.enunciado }}</dt>
                    <dd v-if="textoDaResposta(pergunta, respostas[pergunta.id])" class="break-words whitespace-pre-line">
                        {{ textoDaResposta(pergunta, respostas[pergunta.id]) }}
                    </dd>
                    <dd v-else class="text-tinta-suave italic">Sem resposta</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="revisao-documentos" class="flex flex-col gap-3 border-t border-hairline pt-6">
            <h3 id="revisao-documentos" :class="classeTitulo">Documentos</h3>
            <dl v-if="documentos.length > 0" class="flex flex-col gap-3">
                <div v-for="documento in documentos" :key="documento.id">
                    <dt class="font-semibold break-words">{{ documento.nome }}</dt>
                    <dd class="text-[0.9375rem] text-tinta-suave">
                        <template v-if="documento.enviado">
                            <a :href="documento.enviado.url" class="font-semibold break-all text-acao">{{ documento.enviado.nome }}<span class="sr-only"> (baixar)</span></a>
                            ·
                        </template>
                        {{ situacaoDoDocumento(documento) }}
                    </dd>
                </div>
            </dl>
            <p v-else class="leading-[1.6] text-tinta-suave">Nenhum documento é exigido para as escolhas desta inscrição.</p>
        </section>

        <section aria-labelledby="revisao-envio" class="flex flex-col gap-4 border-t border-hairline pt-6">
            <h3 id="revisao-envio" :class="classeTitulo">{{ inscricao.situacao === 'finalizada' ? 'Inscrição finalizada' : 'Envio' }}</h3>

            <template v-if="inscricao.situacao === 'finalizada'">
                <p class="leading-[1.6]">
                    Número da inscrição <strong class="font-semibold">{{ inscricao.numero }}</strong>.
                    <template v-if="inscricao.finalizadaEm">Enviada em {{ envio.data }} às {{ envio.hora }}.</template>
                </p>
                <ResultadoDaHomologacao v-if="resultado" :resultado="resultado" :inscricao-id="inscricao.id" />
                <div v-if="podeReabrir" class="flex">
                    <button type="button" :class="classeSecundario" @click="abrirReabrir">Reabrir para editar</button>
                </div>
                <!-- Com resultado, o próprio resultado diz o que vale; o aviso é só para quem ainda aguarda a homologação. -->
                <p v-else-if="resultado?.situacao === 'em_homologacao'" class="leading-[1.6] text-tinta-suave">
                    Fora do período de inscrição, a inscrição finalizada continua válida e não pode mais ser alterada.
                </p>
            </template>

            <template v-else-if="podeEditar">
                <p class="leading-[1.6] text-tinta-suave">
                    Finalize para enviar a inscrição. Depois de finalizada, você ainda pode reabri-la para editar até o fim do período, mas ela só conta se você finalizar de novo.
                </p>
                <!-- A região fica sempre na página: a lista de pendências muda enquanto as conferências terminam. -->
                <div id="pendencias-finalizar" aria-live="polite">
                    <template v-if="pendencias.length > 0">
                        <p class="font-semibold">Para finalizar, resolva:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-6 text-invalido-texto">
                            <li v-for="pendencia in pendencias" :key="pendencia">{{ pendencia }}</li>
                        </ul>
                    </template>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-6">
                    <Link v-if="voltarPara" :href="voltarPara" preserve-state :class="classeSecundario">Voltar</Link>
                    <button
                        type="button"
                        :disabled="!podeFinalizar || finalizando || ocupado"
                        :aria-describedby="pendencias.length > 0 ? 'pendencias-finalizar' : undefined"
                        :class="[classePrimario, 'ml-auto']"
                        @click="finalizar"
                    >
                        {{ finalizando ? 'Finalizando…' : 'Finalizar inscrição' }}
                    </button>
                </div>
            </template>

            <p v-else class="leading-[1.6] text-tinta-suave">
                Fora do período de inscrição, esta inscrição não pode mais ser alterada. Como não foi finalizada, ela não conta.
            </p>
        </section>

        <dialog
            ref="dialogoReabrir"
            aria-labelledby="reabrir-titulo"
            aria-describedby="reabrir-aviso"
            class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <h2 id="reabrir-titulo" class="text-[1.25rem] leading-tight font-bold">Reabrir a inscrição?</h2>
            <p id="reabrir-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">
                Ao reabrir, sua inscrição volta a ser rascunho e só conta se você finalizar de novo até {{ inscricao.fimDoPeriodo }}.
            </p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    autofocus
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                    @click="fecharReabrir"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="reabrindo || ocupado"
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    @click="reabrir"
                >
                    {{ reabrindo ? 'Reabrindo…' : 'Reabrir inscrição' }}
                </button>
            </div>
        </dialog>
    </div>
</template>
