<script setup lang="ts">
import { computed } from 'vue';
import { CALOR, degrauDoCalor } from './cores';
import { numero, percentual } from './formatos';

const props = defineProps<{
    colunas: { valor: string; rotulo: string }[];
    /** Percentual de cada coluna, ou nulo quando a linha tem poucas pessoas para mostrar. */
    linhas: { codigo: string; rotulo: string; base: number; valores: (number | null)[] }[];
    legenda: string;
    /** Mínimo de pessoas para o percentual aparecer (explica o "—"). */
    baseMinima: number;
}>();

// Cada coluna tem a sua escala: o tom compara os territórios entre si naquele recorte.
const maximos = computed<number[]>(() => props.colunas.map((_, coluna) => Math.max(0, ...props.linhas.map((linha) => linha.valores[coluna] ?? 0))));
</script>

<template>
    <!-- Tabela de calor: território nas linhas, recortes do perfil nas colunas; o tom mais forte é o maior da coluna. -->
    <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem] border-separate border-spacing-[2px] text-[0.75rem]">
            <caption class="sr-only">{{ legenda }}</caption>
            <thead>
                <tr>
                    <th scope="col" class="sticky left-0 z-10 bg-superficie px-2 pb-2 text-left align-bottom font-semibold text-tinta-suave">Território</th>
                    <th scope="col" class="px-2 pb-2 text-right align-bottom font-semibold text-tinta-suave">Pessoas</th>
                    <th v-for="coluna in colunas" :key="coluna.valor" scope="col" class="max-w-28 px-2 pb-2 text-center align-bottom leading-tight font-semibold text-tinta-suave">
                        {{ coluna.rotulo }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="linha in linhas" :key="linha.codigo">
                    <th scope="row" class="sticky left-0 z-10 bg-superficie px-2 py-1.5 text-left font-medium whitespace-nowrap text-home-tinta">{{ linha.rotulo }}</th>
                    <td class="px-2 py-1.5 text-right text-tinta-suave tabular-nums">{{ numero(linha.base) }}</td>
                    <template v-for="(valor, coluna) in linha.valores" :key="coluna">
                        <td
                            v-if="valor === null"
                            class="rounded-[4px] bg-calor-0 px-2 py-1.5 text-center text-tinta-suave"
                            :data-dica="`Menos de ${baseMinima} pessoas: o percentual não aparece para não identificar ninguém`"
                        >
                            <span aria-hidden="true">—</span><span class="sr-only">Poucas pessoas para mostrar</span>
                        </td>
                        <td
                            v-else
                            class="rounded-[4px] px-2 py-1.5 text-center font-semibold tabular-nums"
                            :class="[CALOR[degrauDoCalor(valor, maximos[coluna])].fundo, CALOR[degrauDoCalor(valor, maximos[coluna])].texto]"
                        >
                            {{ percentual(valor) }}
                        </td>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>
