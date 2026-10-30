<script setup lang="ts">
import { computed } from 'vue';
import { numero, percentual } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = withDefaults(
    defineProps<{
        itens: { rotulo: string; total: number; percentual?: number }[];
        legenda: string;
        /** A maior coluna em cor cheia e as outras em tom mais claro, para a maior saltar aos olhos. */
        destacarMaior?: boolean;
    }>(),
    { destacarMaior: true },
);

const maximo = computed<number>(() => Math.max(...props.itens.map((item) => item.total), 0));
const indiceDoMaior = computed<number>(() => props.itens.findIndex((item) => item.total === maximo.value));
</script>

<template>
    <!-- Colunas em pé (uma série): valor e percentual no topo, rótulo embaixo. -->
    <div>
        <p v-if="maximo === 0" class="py-6 text-center text-[0.8125rem] text-tinta-suave">Nada neste filtro.</p>
        <div v-else aria-hidden="true" class="flex items-end gap-1 border-b border-hairline">
            <div
                v-for="(item, indice) in itens"
                :key="item.rotulo"
                class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1.5"
                :data-dica="`${item.rotulo}: ${numero(item.total)}${item.percentual === undefined ? '' : ` (${percentual(item.percentual)})`}`"
            >
                <span class="text-center leading-tight tabular-nums">
                    <strong class="block text-[0.8125rem] font-semibold text-home-tinta">{{ numero(item.total) }}</strong>
                    <span v-if="item.percentual !== undefined" class="block text-[0.6875rem] text-tinta-suave">{{ percentual(item.percentual) }}</span>
                </span>
                <span class="flex h-32 w-full items-end justify-center">
                    <span
                        class="w-6 rounded-t-[4px] bg-serie-1"
                        :class="destacarMaior && indice !== indiceDoMaior ? 'opacity-45' : ''"
                        :style="{ height: `${Math.max((item.total / maximo) * 100, item.total > 0 ? 2 : 0)}%` }"
                    />
                </span>
            </div>
        </div>
        <div v-if="maximo > 0" aria-hidden="true" class="mt-2 flex gap-1">
            <span v-for="item in itens" :key="item.rotulo" class="min-w-0 flex-1 text-center text-[0.6875rem] leading-tight text-tinta-suave">{{ item.rotulo }}</span>
        </div>
        <TabelaLeitor
            :legenda="legenda"
            :colunas="['Faixa', 'Total', 'Percentual']"
            :linhas="itens.map((item) => [item.rotulo, numero(item.total), item.percentual === undefined ? '—' : percentual(item.percentual)])"
        />
    </div>
</template>
