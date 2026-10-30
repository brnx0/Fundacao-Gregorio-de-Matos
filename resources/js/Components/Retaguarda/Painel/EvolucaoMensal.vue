<script setup lang="ts">
import { computed } from 'vue';
import type { MesDeCadastrosPainel } from '../../../types';
import { NEUTRA, SERIES, type CorDeSerie } from './cores';
import { mesCurto, numero } from './formatos';
import MiniLinha from './MiniLinha.vue';
import TabelaLeitor from './TabelaLeitor.vue';

const props = defineProps<{
    meses: MesDeCadastrosPainel[];
    legenda: string;
}>();

type Grupo = 'proponentes' | 'patrocinadores' | 'ambos' | 'semTipo';

const GRUPOS: { chave: Grupo; rotulo: string; cor: CorDeSerie }[] = [
    { chave: 'proponentes', rotulo: 'Proponentes', cor: SERIES[0] },
    { chave: 'patrocinadores', rotulo: 'Patrocinadores', cor: SERIES[1] },
    { chave: 'ambos', rotulo: 'Proponentes e patrocinadores', cor: SERIES[2] },
    { chave: 'semTipo', rotulo: 'Ainda sem tipo', cor: NEUTRA },
];

/** Topo do eixo arredondado para cima (1, 2 ou 5 vezes uma potência de 10), com a metade como marca do meio. */
const topo = computed<number>(() => {
    const maximo = Math.max(...props.meses.map((mes) => mes.total), 0);
    if (maximo === 0) return 0;
    const potencia = 10 ** Math.floor(Math.log10(maximo));
    return ([1, 2, 5, 10].map((fator) => fator * potencia).find((valor) => valor >= maximo) ?? maximo);
});

/** Rótulos do eixo de meses: no máximo uns 8, para não se atropelarem. */
const passo = computed<number>(() => Math.max(1, Math.ceil(props.meses.length / 8)));

const dica = (mes: MesDeCadastrosPainel): string =>
    `${mesCurto(mes.mes)}: ${numero(mes.total)} ${mes.total === 1 ? 'novo cadastro' : 'novos cadastros'} (${GRUPOS.map((grupo) => `${grupo.rotulo.toLowerCase()}: ${numero(mes[grupo.chave])}`).join('; ')})`;
</script>

<template>
    <!-- Novos cadastros por mês em colunas empilhadas pelo tipo e, num gráfico à parte (escala própria), o acumulado. -->
    <div class="flex flex-col gap-4">
        <ul aria-hidden="true" class="flex flex-wrap gap-x-4 gap-y-1 text-[0.75rem] text-tinta-suave">
            <li v-for="grupo in GRUPOS" :key="grupo.chave" class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-[3px]" :class="grupo.cor.fundo" />{{ grupo.rotulo }}
            </li>
        </ul>

        <p v-if="topo === 0" class="py-10 text-center text-[0.8125rem] text-tinta-suave">Nenhum cadastro no período.</p>
        <div v-else aria-hidden="true">
            <div class="relative ml-10 h-52 border-b border-hairline">
                <!-- Marcas do eixo: 0, metade e topo, em linhas finas (sem a metade quando o topo é 1: contagem não tem meio). -->
                <span
                    v-for="fracao in topo >= 2 ? [0.5, 1] : [1]"
                    :key="fracao"
                    class="absolute inset-x-0 border-t border-hairline"
                    :style="{ bottom: `${fracao * 100}%` }"
                >
                    <span class="absolute -left-10 w-8 -translate-y-1/2 text-right text-[0.6875rem] text-tinta-suave tabular-nums">{{ numero(topo * fracao) }}</span>
                </span>
                <span class="absolute bottom-0 -left-10 w-8 translate-y-1/2 text-right text-[0.6875rem] text-tinta-suave">0</span>
                <div class="relative flex h-full items-end gap-[3px]">
                    <div v-for="mes in meses" :key="mes.mes" class="flex h-full min-w-0 flex-1 items-end justify-center" :data-dica="dica(mes)">
                        <span class="flex w-full max-w-6 flex-col-reverse gap-[2px]" :style="{ height: `${(mes.total / topo) * 100}%` }">
                            <span
                                v-for="(grupo, indice) in GRUPOS.filter((grupo) => mes[grupo.chave] > 0)"
                                :key="grupo.chave"
                                class="min-h-px"
                                :class="[grupo.cor.fundo, indice === GRUPOS.filter((outro) => mes[outro.chave] > 0).length - 1 ? 'rounded-t-[4px]' : '']"
                                :style="{ flexGrow: mes[grupo.chave], flexBasis: 0 }"
                            />
                        </span>
                    </div>
                </div>
            </div>
            <div class="mt-2 ml-10 flex gap-[3px]">
                <span v-for="(mes, indice) in meses" :key="mes.mes" class="min-w-0 flex-1 text-center text-[0.6875rem] whitespace-nowrap text-tinta-suave">
                    {{ indice % passo === 0 ? mesCurto(mes.mes) : '' }}
                </span>
            </div>
        </div>

        <div v-if="topo > 0" class="flex items-center gap-4 border-t border-hairline pt-3">
            <p class="shrink-0 text-[0.75rem] text-tinta-suave">
                Total acumulado
                <strong class="block text-[1.125rem] font-bold text-home-tinta">{{ numero(meses.at(-1)?.acumulado ?? 0) }}</strong>
            </p>
            <div class="min-w-0 flex-1">
                <MiniLinha
                    :valores="meses.map((mes) => mes.acumulado)"
                    :descricao="`Total acumulado de cadastros: de ${numero(meses[0]?.acumulado ?? 0)} em ${mesCurto(meses[0]?.mes ?? '')} a ${numero(meses.at(-1)?.acumulado ?? 0)} em ${mesCurto(meses.at(-1)?.mes ?? '')}`"
                />
            </div>
        </div>

        <TabelaLeitor
            :legenda="legenda"
            :colunas="['Mês', ...GRUPOS.map((grupo) => grupo.rotulo), 'Total do mês', 'Acumulado']"
            :linhas="meses.map((mes) => [mesCurto(mes.mes), ...GRUPOS.map((grupo) => numero(mes[grupo.chave])), numero(mes.total), numero(mes.acumulado)])"
        />
    </div>
</template>
