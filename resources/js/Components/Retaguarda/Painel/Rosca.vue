<script setup lang="ts">
import { computed } from 'vue';
import type { FatiaPainel } from '../../../types';
import { corDaSerie } from './cores';
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = defineProps<{
    itens: FatiaPainel[];
    legenda: string;
    /** Número grande no meio e a palavra embaixo dele. */
    centro: { valor: string; rotulo: string };
}>();

const RAIO = 40;
const CIRCUNFERENCIA = 2 * Math.PI * RAIO;
/** Folga entre as fatias, no fundo do quadro (separa sem contorno). */
const FOLGA = 1.6;

const soma = computed<number>(() => props.itens.reduce((total, item) => total + item.total, 0));

// Cada fatia é um traço do círculo que começa onde a anterior terminou (a partir do topo).
const fatias = computed(() => {
    let inicio = 0;
    return props.itens.map((item, indice) => {
        const tamanho = soma.value > 0 ? (item.total / soma.value) * CIRCUNFERENCIA : 0;
        const fatia = {
            ...item,
            cor: corDaSerie(indice, item.valor),
            traco: `${Math.max(tamanho - FOLGA, 0)} ${CIRCUNFERENCIA}`,
            deslocamento: -inicio,
        };
        inicio += tamanho;
        return fatia;
    });
});
</script>

<template>
    <!-- Rosca com o total no meio e a legenda ao lado, com valor e percentual de cada fatia. -->
    <div class="flex flex-col items-center gap-5 @xl:flex-row @xl:items-center">
        <div class="relative size-44 shrink-0">
            <svg aria-hidden="true" viewBox="0 0 100 100" class="size-full -rotate-90">
                <circle cx="50" cy="50" :r="RAIO" class="fill-none stroke-trilho" stroke-width="14" />
                <circle
                    v-for="fatia in fatias.filter((fatia) => fatia.total > 0)"
                    :key="fatia.valor"
                    cx="50"
                    cy="50"
                    :r="RAIO"
                    class="fill-none"
                    :class="fatia.cor.traco"
                    stroke-width="14"
                    :stroke-dasharray="fatia.traco"
                    :stroke-dashoffset="fatia.deslocamento"
                    :data-dica="`${fatia.rotulo}: ${numero(fatia.total)} (${percentual(fatia.percentual)})`"
                />
            </svg>
            <p class="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span class="text-[1.375rem] leading-none font-bold text-home-tinta">{{ centro.valor }}</span>
                <span class="mt-1 text-[0.6875rem] text-tinta-suave">{{ centro.rotulo }}</span>
            </p>
        </div>
        <ul aria-hidden="true" class="flex w-full min-w-0 flex-col gap-2 text-[0.8125rem]">
            <li v-for="fatia in fatias" :key="fatia.valor" class="flex items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full" :class="fatia.cor.fundo" />
                <span class="min-w-0 flex-1 truncate text-tinta-suave">{{ fatia.rotulo }}</span>
                <span class="shrink-0 tabular-nums">
                    <strong class="font-semibold text-home-tinta">{{ percentual(fatia.percentual) }}</strong>
                    <span class="ml-1 text-[0.75rem] text-tinta-suave">{{ numero(fatia.total) }}</span>
                </span>
            </li>
        </ul>
        <TabelaLeitor :legenda="legenda" :colunas="['Categoria', 'Total', 'Percentual']" :linhas="itens.map((item) => [item.rotulo, numero(item.total), percentual(item.percentual)])" />
    </div>
</template>
