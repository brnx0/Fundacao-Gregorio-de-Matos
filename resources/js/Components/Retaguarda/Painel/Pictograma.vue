<script setup lang="ts">
import { computed } from 'vue';
import type { FatiaPainel } from '../../../types';
import { corDaSerie, type CorDeSerie } from './cores';
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = defineProps<{
    grupos: FatiaPainel[];
    legenda: string;
}>();

/** Cada ícone vale 5% das pessoas que responderam. */
const ICONES = 20;

// Ícones por grupo pelo maior resto: a soma dá sempre 20, e um grupo pequeno não some por arredondamento para baixo.
const icones = computed<{ cor: CorDeSerie; rotulo: string }[]>(() => {
    const soma = props.grupos.reduce((total, grupo) => total + grupo.total, 0);
    if (soma === 0) return [];
    const cotas = props.grupos.map((grupo, indice) => {
        const exata = (grupo.total / soma) * ICONES;
        return { indice, inteira: Math.floor(exata), resto: exata - Math.floor(exata) };
    });
    let faltam = ICONES - cotas.reduce((total, cota) => total + cota.inteira, 0);
    [...cotas].sort((a, b) => b.resto - a.resto).forEach((cota) => {
        if (faltam > 0) {
            cota.inteira += 1;
            faltam -= 1;
        }
    });
    return cotas.flatMap((cota) =>
        Array.from({ length: cota.inteira }, () => ({ cor: corDaSerie(cota.indice, props.grupos[cota.indice].valor), rotulo: props.grupos[cota.indice].rotulo })),
    );
});

const principais = computed<FatiaPainel[]>(() => props.grupos.slice(0, 2));
</script>

<template>
    <!-- Pictograma de pessoas: 20 ícones, cada um 5%, pintados pelo grupo; os dois maiores grupos em destaque embaixo. -->
    <div class="flex flex-col gap-4">
        <p v-if="icones.length === 0" class="py-6 text-center text-[0.8125rem] text-tinta-suave">Nada neste filtro.</p>
        <template v-else>
            <div aria-hidden="true" class="grid grid-cols-10 gap-x-2 gap-y-3 justify-items-center">
                <span v-for="(icone, indice) in icones" :key="indice" class="inline-flex" :data-dica="icone.rotulo">
                    <svg viewBox="0 0 24 24" class="h-7 w-5">
                        <circle cx="12" cy="6" r="4" :class="icone.cor.preenche" />
                        <path d="M5 22v-5a7 7 0 0 1 14 0v5z" :class="icone.cor.preenche" />
                    </svg>
                </span>
            </div>
            <p aria-hidden="true" class="text-center text-[0.6875rem] text-tinta-suave">Cada ícone é 5% das pessoas que responderam</p>
            <div aria-hidden="true" class="grid grid-cols-2 gap-4">
                <div v-for="(grupo, indice) in principais" :key="grupo.valor" :class="indice === 1 ? 'text-right' : ''">
                    <p class="text-[1.75rem] leading-none font-bold text-home-tinta">{{ percentual(grupo.percentual) }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-[0.75rem] text-tinta-suave" :class="indice === 1 ? 'justify-end' : ''">
                        <span class="size-2.5 rounded-full" :class="corDaSerie(indice, grupo.valor).fundo" />
                        {{ grupo.rotulo }} · {{ numero(grupo.total) }}
                    </p>
                </div>
            </div>
            <ul aria-hidden="true" class="flex flex-wrap gap-x-4 gap-y-1 border-t border-hairline pt-3 text-[0.75rem]">
                <li v-for="(grupo, indice) in grupos.slice(2)" :key="grupo.valor" class="flex items-center gap-1.5 text-tinta-suave">
                    <span class="size-2.5 rounded-full" :class="corDaSerie(indice + 2, grupo.valor).fundo" />
                    {{ grupo.rotulo }}: <strong class="font-semibold text-home-tinta">{{ percentual(grupo.percentual) }}</strong>
                </li>
            </ul>
        </template>
        <TabelaLeitor :legenda="legenda" :colunas="['Grupo', 'Total', 'Percentual']" :linhas="grupos.map((grupo) => [grupo.rotulo, numero(grupo.total), percentual(grupo.percentual)])" />
    </div>
</template>
