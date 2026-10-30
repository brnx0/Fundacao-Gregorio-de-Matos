<script setup lang="ts">
import { computed } from 'vue';
import { numero, percentual as formatarPercentual } from './formatos';

const props = withDefaults(
    defineProps<{
        rotulo: string;
        total: number;
        /** Base do percentual (o "de quantos"). */
        base: number;
        percentual: number;
        /** Palavra da base no rodapé: "pessoas", "editais publicados". */
        unidade?: string;
        /** Classe de traço do anel. */
        cor?: string;
    }>(),
    { unidade: 'pessoas', cor: 'stroke-serie-1' },
);

const RAIO = 15.5;
const CIRCUNFERENCIA = 2 * Math.PI * RAIO;
const traco = computed<string>(() => `${(Math.min(props.percentual, 100) / 100) * CIRCUNFERENCIA} ${CIRCUNFERENCIA}`);
</script>

<template>
    <!-- Indicador de percentual: anel com o número no meio, o nome e o "N de M" ao lado. -->
    <div class="flex min-w-0 items-center gap-3 rounded-[14px] border border-hairline bg-superficie p-3">
        <span aria-hidden="true" class="relative size-14 shrink-0">
            <svg viewBox="0 0 36 36" class="size-full -rotate-90">
                <circle cx="18" cy="18" :r="RAIO" class="fill-none stroke-trilho" stroke-width="3.5" />
                <circle v-if="percentual > 0" cx="18" cy="18" :r="RAIO" class="fill-none" :class="cor" stroke-width="3.5" stroke-linecap="round" :stroke-dasharray="traco" />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-[0.6875rem] font-bold text-home-tinta">{{ formatarPercentual(percentual) }}</span>
        </span>
        <span class="min-w-0">
            <span class="block text-[0.8125rem] leading-snug font-semibold text-home-tinta">{{ rotulo }}</span>
            <span class="block text-[0.75rem] text-tinta-suave">
                <span class="sr-only">{{ formatarPercentual(percentual) }}: </span>{{ numero(total) }} de {{ numero(base) }} {{ unidade }}
            </span>
        </span>
    </div>
</template>
