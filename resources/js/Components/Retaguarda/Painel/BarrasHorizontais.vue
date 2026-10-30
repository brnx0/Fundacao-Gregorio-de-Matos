<script setup lang="ts">
import { computed } from 'vue';
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = withDefaults(
    defineProps<{
        itens: { rotulo: string; total: number; percentual: number }[];
        /** Legenda da tabela para o leitor de tela. */
        legenda: string;
        /** Classe de fundo da barra (uma série só). */
        cor?: string;
        /** Valor já formatado no lugar do total (ex.: reais). */
        formatar?: (total: number) => string;
        /** Mostra o percentual ao lado do valor. */
        comPercentual?: boolean;
    }>(),
    { cor: 'bg-serie-1', formatar: numero, comPercentual: true },
);

const maximo = computed<number>(() => Math.max(...props.itens.map((item) => item.total), 0));
</script>

<template>
    <!-- Barras deitadas, do mesmo tamanho de escala: rótulo à esquerda, valor e percentual na ponta. -->
    <div>
        <p v-if="maximo === 0" class="py-6 text-center text-[0.8125rem] text-tinta-suave">Nada neste filtro.</p>
        <ul v-else aria-hidden="true" class="flex flex-col gap-2.5">
            <li
                v-for="item in itens"
                :key="item.rotulo"
                class="grid grid-cols-[minmax(0,42%)_minmax(0,1fr)] items-center gap-3 text-[0.8125rem] @xl:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]"
                :data-dica="`${item.rotulo}: ${formatar(item.total)}${comPercentual ? ` (${percentual(item.percentual)})` : ''}`"
            >
                <span class="line-clamp-2 text-right leading-tight text-tinta-suave">{{ item.rotulo }}</span>
                <span class="flex min-w-0 items-center gap-2">
                    <span class="relative h-2.5 min-w-0 flex-1 rounded-[4px] bg-trilho">
                        <span class="absolute inset-y-0 left-0 rounded-r-[4px]" :class="cor" :style="{ width: `${(item.total / maximo) * 100}%` }" />
                    </span>
                    <span class="w-28 shrink-0 tabular-nums">
                        <strong class="font-semibold text-home-tinta">{{ formatar(item.total) }}</strong>
                        <span v-if="comPercentual" class="ml-1 text-[0.75rem] text-tinta-suave">{{ percentual(item.percentual) }}</span>
                    </span>
                </span>
            </li>
        </ul>
        <TabelaLeitor
            :legenda="legenda"
            :colunas="comPercentual ? ['Item', 'Total', 'Percentual'] : ['Item', 'Total']"
            :linhas="itens.map((item) => (comPercentual ? [item.rotulo, formatar(item.total), percentual(item.percentual)] : [item.rotulo, formatar(item.total)]))"
        />
    </div>
</template>
