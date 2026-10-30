<script setup lang="ts">
import { computed } from 'vue';
import type { FatiaPainel } from '../../../types';
import { CALOR, corDaSerie } from './cores';
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = withDefaults(
    defineProps<{
        itens: FatiaPainel[];
        legenda: string;
        /** "ordinal" para faixas em ordem (renda): do claro ao escuro de um tom só; "categorias" para grupos sem ordem. */
        escala?: 'categorias' | 'ordinal';
    }>(),
    { escala: 'categorias' },
);

const soma = computed<number>(() => props.itens.reduce((total, item) => total + item.total, 0));

// No ordinal, as faixas vão dos degraus claros aos escuros do calor (o "não informado" fica neutro).
const corDe = (indice: number, valor: string): string => {
    if (props.escala === 'categorias') return corDaSerie(indice, valor).fundo;
    const degrau = Math.round(2 + (indice / Math.max(props.itens.length - 1, 1)) * 4);
    return CALOR[degrau].fundo;
};
</script>

<template>
    <!-- Barra de 100% em segmentos (com folga entre eles) e a legenda embaixo com o percentual de cada um. -->
    <div class="flex flex-col gap-4">
        <p v-if="soma === 0" class="py-6 text-center text-[0.8125rem] text-tinta-suave">Nada neste filtro.</p>
        <template v-else>
            <div aria-hidden="true" class="flex h-4 w-full gap-0.5 overflow-hidden rounded-[4px]">
                <span
                    v-for="item in itens.filter((item) => item.total > 0)"
                    :key="item.valor"
                    class="h-full"
                    :class="corDe(itens.indexOf(item), item.valor)"
                    :style="{ width: `${(item.total / soma) * 100}%` }"
                    :data-dica="`${item.rotulo}: ${numero(item.total)} (${percentual(item.percentual)})`"
                />
            </div>
            <ul aria-hidden="true" class="grid gap-x-4 gap-y-2 text-[0.75rem] @xl:grid-cols-2">
                <li v-for="(item, indice) in itens" :key="item.valor" class="flex items-center gap-2">
                    <span class="size-2.5 shrink-0 rounded-[3px]" :class="corDe(indice, item.valor)" />
                    <span class="min-w-0 flex-1 truncate text-tinta-suave">{{ item.rotulo }}</span>
                    <strong class="shrink-0 font-semibold text-home-tinta tabular-nums">{{ percentual(item.percentual) }}</strong>
                </li>
            </ul>
        </template>
        <TabelaLeitor :legenda="legenda" :colunas="['Categoria', 'Total', 'Percentual']" :linhas="itens.map((item) => [item.rotulo, numero(item.total), percentual(item.percentual)])" />
    </div>
</template>
