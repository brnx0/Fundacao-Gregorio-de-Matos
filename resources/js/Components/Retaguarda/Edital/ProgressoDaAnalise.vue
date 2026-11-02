<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
    /** Nome do arquivo, para o leitor de tela saber de qual análise é a barra. */
    pdfNome: string;
    /** Tempo desde o início da análise quando a página o recebeu; daí em diante o relógio daqui conta. */
    segundosDecorridos: number;
    /** Quanto a análise costuma levar (mediana das últimas concluídas). */
    segundosPrevistos: number;
    /** A análise terminou: a barra se completa antes de a linha mudar para "Concluída". */
    concluida?: boolean;
    /** O que está sendo analisado, ao lado do percentual (a importação do questionário usa a mesma barra). */
    titulo?: string;
    /** O que a conclusão fez, embaixo da barra completa. */
    textoConcluida?: string;
    /** Ocupa toda a largura de onde está (o cartão da importação do questionário), sem o limite da lista de editais. */
    larga?: boolean;
    }>(),
    { concluida: false, titulo: 'Analisando o edital…', textoConcluida: 'Edital criado.', larga: false },
);

// A IA não informa o andamento: o progresso é estimado pelo tempo. Sobe em ritmo constante até 85% no tempo previsto
// e depois se aproxima de 99% sem chegar; só a conclusão leva a 100%.
const NO_PREVISTO = 85;
const TETO = 99;

const recebidoEm = ref<number>(Date.now());
const agora = ref<number>(Date.now());
watch(
    () => props.segundosDecorridos,
    () => {
        recebidoEm.value = Date.now();
        agora.value = recebidoEm.value;
    },
);

const relogio = setInterval(() => {
    agora.value = Date.now();
}, 1000);
onBeforeUnmount(() => clearInterval(relogio));

const decorridos = computed<number>(() => props.segundosDecorridos + (agora.value - recebidoEm.value) / 1000);
const previstos = computed<number>(() => Math.max(1, props.segundosPrevistos));

const percentual = computed<number>(() => {
    if (props.concluida) return 100;

    const fracao = decorridos.value / previstos.value;
    if (fracao <= 1) return Math.round(NO_PREVISTO * fracao);

    const alem = decorridos.value - previstos.value;
    return Math.min(TETO, Math.round(NO_PREVISTO + (TETO - NO_PREVISTO) * (1 - Math.exp(-alem / (previstos.value / 2)))));
});

const restante = computed<string>(() => {
    if (props.concluida) return props.textoConcluida;

    const segundos = previstos.value - decorridos.value;
    if (segundos <= 0) return 'Está levando um pouco mais que o normal.';
    if (segundos < 60) return 'Falta menos de 1 minuto.';

    const minutos = Math.round(segundos / 60);
    return `Faltam cerca de ${minutos} ${minutos === 1 ? 'minuto' : 'minutos'}.`;
});
</script>

<template>
    <div :class="larga ? 'w-full' : 'w-full @3xl:w-80'">
        <div class="flex items-baseline justify-between gap-3">
            <p class="inline-flex items-center gap-2 text-[0.9375rem] font-semibold text-acao">
                <span aria-hidden="true" class="relative flex size-2">
                    <span v-if="!concluida" class="absolute inline-flex size-full rounded-full bg-acao opacity-60 motion-safe:animate-ping" />
                    <span class="relative inline-flex size-2 rounded-full bg-acao" />
                </span>
                {{ concluida ? 'Análise concluída' : titulo }}
            </p>
            <span aria-hidden="true" class="font-barlow text-[1.25rem] leading-none font-extrabold text-acao tabular-nums">{{ percentual }}%</span>
        </div>

        <div
            role="progressbar"
            :aria-label="`Progresso estimado da análise de ${pdfNome}`"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="percentual"
            :aria-valuetext="`${percentual}% (estimativa). ${restante}`"
            class="barra-progresso mt-2"
        >
            <div class="barra-progresso-preenchimento" :class="{ 'barra-progresso-ativa': !concluida }" :style="{ width: `${Math.max(percentual, 3)}%` }" />
        </div>

        <p aria-hidden="true" class="mt-1.5 text-[0.8125rem] text-tinta-suave">{{ restante }}</p>
    </div>
</template>
