<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    valores: number[];
    /** Frase para o leitor de tela (o desenho sozinho não diz os números). */
    descricao: string;
}>();

const LARGURA = 120;
const ALTURA = 36;
const MARGEM = 3;

const pontos = computed<[number, number][]>(() => {
    const maximo = Math.max(...props.valores, 0);
    const passo = props.valores.length > 1 ? LARGURA / (props.valores.length - 1) : 0;
    return props.valores.map((valor, indice) => [indice * passo, maximo > 0 ? ALTURA - MARGEM - (valor / maximo) * (ALTURA - 2 * MARGEM) : ALTURA - MARGEM]);
});

const linha = computed<string>(() => pontos.value.map(([x, y], indice) => `${indice === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`).join(' '));
const area = computed<string>(() => (pontos.value.length > 1 ? `${linha.value} L${LARGURA} ${ALTURA} L0 ${ALTURA} Z` : ''));
const ultimo = computed<[number, number]>(() => pontos.value.at(-1) ?? [LARGURA, ALTURA - MARGEM]);
</script>

<template>
    <!-- Minigráfico de linha: tendência dos meses, com o ponto do mês atual. A escala estica na largura do cartão. -->
    <div role="img" :aria-label="descricao" class="relative h-12">
        <svg aria-hidden="true" :viewBox="`0 0 ${LARGURA} ${ALTURA}`" preserveAspectRatio="none" class="size-full overflow-visible">
            <path v-if="area" :d="area" class="fill-serie-1 opacity-10" />
            <path :d="linha" class="fill-none stroke-serie-1" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
        </svg>
        <!-- O ponto fica fora do SVG esticado para continuar redondo. -->
        <span
            aria-hidden="true"
            class="absolute size-2.5 -translate-1/2 rounded-full bg-serie-1 ring-2 ring-superficie"
            :style="{ left: `${(ultimo[0] / LARGURA) * 100}%`, top: `${(ultimo[1] / ALTURA) * 100}%` }"
        />
    </div>
</template>
