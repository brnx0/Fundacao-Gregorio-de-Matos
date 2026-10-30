<script setup lang="ts">
import { computed } from 'vue';
import type { PainelTerritorio } from '../../../../types';
import BarrasHorizontais from '../BarrasHorizontais.vue';
import { numero, percentual } from '../formatos';
import MapaDeBairros from '../MapaDeBairros.vue';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';
import TabelaLeitor from '../TabelaLeitor.vue';

const props = defineProps<{
    dados: PainelTerritorio;
    atualizando?: boolean;
}>();

const BAIRROS_NO_RANKING = 10;

const pico = computed(() => props.dados.bairros[0] ?? null);
const media = computed<number>(() => (props.dados.totalDeBairros > 0 ? props.dados.mapeadas / props.dados.totalDeBairros : 0));
const ranking = computed(() =>
    props.dados.bairros.slice(0, BAIRROS_NO_RANKING).map((bairro) => ({
        rotulo: bairro.nome,
        total: bairro.total,
        percentual: props.dados.mapeadas > 0 ? Math.round((bairro.total / props.dados.mapeadas) * 1000) / 10 : 0,
    })),
);
</script>

<template>
    <SecaoPainel
        id="territorio"
        titulo="Território"
        descricao="Onde moram as pessoas cadastradas: o bairro digitado no cadastro e a Prefeitura-Bairro escolhida"
        icone="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"
        :atualizando="atualizando"
    >
        <div class="grid gap-4 @6xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
            <QuadroPainel
                titulo="Cadastros por bairro de Salvador"
                descricao="Cada bairro é pintado pela quantidade de pessoas que moram nele: quanto mais escuro, mais cadastros. Entra só quem mora em Salvador."
            >
                <dl class="flex flex-wrap gap-x-6 gap-y-1 text-[0.8125rem] text-tinta-suave">
                    <div v-if="pico" class="flex gap-1">
                        <dt>Pico:</dt>
                        <dd class="font-semibold text-home-tinta">{{ pico.nome }} · {{ numero(pico.total) }}</dd>
                    </div>
                    <div class="flex gap-1">
                        <dt>Média por bairro:</dt>
                        <dd class="font-semibold text-home-tinta">{{ numero(Math.round(media * 10) / 10) }}</dd>
                    </div>
                    <div class="flex gap-1">
                        <dt>Reconhecidos:</dt>
                        <dd>
                            <span class="font-semibold text-home-tinta">{{ numero(dados.mapeadas) }}</span> de {{ numero(dados.emSalvador) }} cadastros em Salvador
                        </dd>
                    </div>
                </dl>
                <MapaDeBairros :bairros="dados.bairros" rotulo="Mapa dos cadastros por bairro de Salvador" />
                <TabelaLeitor
                    legenda="Cadastros por bairro de Salvador"
                    :colunas="['Bairro', 'Cadastros']"
                    :linhas="dados.bairros.map((bairro) => [bairro.nome, numero(bairro.total)])"
                />
            </QuadroPainel>

            <div class="grid grid-rows-[auto_1fr] gap-4">
                <QuadroPainel titulo="Por Prefeitura-Bairro" descricao="Território escolhido no cadastro, sobre todas as pessoas no filtro">
                    <BarrasHorizontais
                        :itens="dados.prefeituras"
                        legenda="Cadastros por Prefeitura-Bairro"
                    />
                </QuadroPainel>
                <QuadroPainel titulo="Bairros com mais cadastros" :descricao="`Os ${BAIRROS_NO_RANKING} primeiros; percentual sobre os cadastros reconhecidos`">
                    <BarrasHorizontais :itens="ranking" legenda="Bairros com mais cadastros" cor="bg-calor-5" />
                </QuadroPainel>
            </div>
        </div>

        <details class="group rounded-[16px] border border-hairline bg-superficie p-4 @xl:px-5">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-[0.9375rem] font-bold text-home-tinta">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m9 6 6 6-6 6" />
                </svg>
                Bairros não reconhecidos: {{ numero(dados.naoReconhecidas) }}
                <span class="font-normal text-tinta-suave">({{ percentual(dados.emSalvador > 0 ? Math.round((dados.naoReconhecidas / dados.emSalvador) * 1000) / 10 : 0) }} dos cadastros em Salvador)</span>
            </summary>
            <div class="mt-3 flex flex-col gap-2 text-[0.8125rem] text-tinta-suave">
                <p>
                    O bairro é digitado no cadastro (ou vem do CEP). Estes textos não batem com a lista oficial de bairros, mesmo sem
                    acento e sem diferença de maiúsculas. Aparecem só os que se repetem, porque um texto único pode ser um endereço inteiro.
                </p>
                <ul v-if="dados.textosNaoReconhecidos.length" class="flex flex-wrap gap-2">
                    <li v-for="texto in dados.textosNaoReconhecidos" :key="texto.texto" class="selo">
                        {{ texto.texto }} · {{ numero(texto.total) }}
                    </li>
                </ul>
                <p v-else class="italic">Nenhum texto se repete.</p>
            </div>
        </details>
    </SecaoPainel>
</template>
