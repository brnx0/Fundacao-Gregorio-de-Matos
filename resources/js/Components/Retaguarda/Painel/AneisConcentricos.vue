<script setup lang="ts">
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

defineProps<{
    /** Um anel por item, de fora para dentro; o arco é o percentual (0 a 100). */
    itens: { rotulo: string; total: number; percentual: number; cor: string; traco: string }[];
    legenda: string;
    centro: { valor: string; rotulo: string };
}>();

const RAIO_EXTERNO = 44;
const DISTANCIA = 9;
const circunferencia = (indice: number): number => 2 * Math.PI * (RAIO_EXTERNO - indice * DISTANCIA);
</script>

<template>
    <!-- Anéis concêntricos: cada anel é uma etapa, com o total e a legenda à esquerda. -->
    <div class="flex flex-col items-center gap-6 @xl:flex-row">
        <div class="flex w-full min-w-0 flex-col gap-4 @xl:w-auto @xl:min-w-56 @xl:flex-1">
            <p>
                <span class="block text-[2rem] leading-none font-bold tracking-[-0.02em] text-home-tinta">{{ centro.valor }}</span>
                <span class="mt-1 block text-[0.75rem] text-tinta-suave">{{ centro.rotulo }}</span>
            </p>
            <ul aria-hidden="true" class="flex flex-col gap-2.5 text-[0.8125rem]">
                <li v-for="item in itens" :key="item.rotulo" class="flex items-center gap-2">
                    <span class="size-2.5 shrink-0 rounded-full" :class="item.cor" />
                    <span class="min-w-0 flex-1 text-tinta-suave">{{ item.rotulo }}</span>
                    <span class="shrink-0 tabular-nums">
                        <strong class="font-semibold text-home-tinta">{{ percentual(item.percentual) }}</strong>
                        <span class="ml-1 text-[0.75rem] text-tinta-suave">{{ numero(item.total) }}</span>
                    </span>
                </li>
            </ul>
        </div>
        <div class="size-48 shrink-0">
            <svg aria-hidden="true" viewBox="0 0 100 100" class="size-full -rotate-90">
                <template v-for="(item, indice) in itens" :key="item.rotulo">
                    <circle cx="50" cy="50" :r="RAIO_EXTERNO - indice * DISTANCIA" class="fill-none stroke-trilho" stroke-width="6" />
                    <circle
                        v-if="item.percentual > 0"
                        cx="50"
                        cy="50"
                        :r="RAIO_EXTERNO - indice * DISTANCIA"
                        class="fill-none"
                        :class="item.traco"
                        stroke-width="6"
                        stroke-linecap="round"
                        :stroke-dasharray="`${(Math.min(item.percentual, 100) / 100) * circunferencia(indice)} ${circunferencia(indice)}`"
                        :data-dica="`${item.rotulo}: ${numero(item.total)} (${percentual(item.percentual)})`"
                    />
                </template>
            </svg>
        </div>
        <TabelaLeitor :legenda="legenda" :colunas="['Etapa', 'Total', 'Percentual']" :linhas="itens.map((item) => [item.rotulo, numero(item.total), percentual(item.percentual)])" />
    </div>
</template>
