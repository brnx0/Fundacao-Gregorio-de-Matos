<script setup lang="ts">
import { computed } from 'vue';
import type { PainelPessoas } from '../../../../types';
import AnelPercentual from '../AnelPercentual.vue';
import BarraProporcao from '../BarraProporcao.vue';
import BarrasHorizontais from '../BarrasHorizontais.vue';
import Colunas from '../Colunas.vue';
import { numero, percentual, quantos } from '../formatos';
import Pictograma from '../Pictograma.vue';
import QuadroPainel from '../QuadroPainel.vue';
import Rosca from '../Rosca.vue';
import SecaoPainel from '../SecaoPainel.vue';

const props = defineProps<{
    dados: PainelPessoas;
    atualizando?: boolean;
}>();

/** "Sem resposta" no rodapé: cadastros antigos não têm os campos de perfil que vieram depois. */
const semResposta = (quantas: number): string => (quantas > 0 ? ` · ${numero(quantas)} sem resposta` : '');

const rendaAteUm = computed<number>(() =>
    props.dados.renda.itens.filter((item) => ['nenhuma', 'ate_1'].includes(item.valor)).reduce((total, item) => total + item.percentual, 0),
);

/** No quadro, só as mais marcadas; a lista completa (com as que ninguém marcou) fica em "Ver todas as áreas". */
const AREAS_NO_QUADRO = 10;
const areasMaisMarcadas = computed(() => props.dados.areas.itens.filter((area) => area.total > 0).slice(0, AREAS_NO_QUADRO));
</script>

<template>
    <SecaoPainel
        id="pessoas"
        titulo="Pessoas"
        descricao="Perfil de quem se cadastrou como pessoa física: proponentes e patrocinadores"
        icone="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"
        :atualizando="atualizando"
    >
        <template #extra>
            <span class="selo self-start @3xl:self-auto">{{ quantos(dados.total, 'pessoa', 'pessoas') }} no filtro</span>
        </template>

        <div class="grid gap-4 @5xl:grid-cols-3">
            <QuadroPainel titulo="Gênero" :descricao="`Identidade de gênero declarada${semResposta(dados.genero.semResposta)}`">
                <Pictograma :grupos="dados.gruposDeGenero" legenda="Pessoas por grupo de identidade de gênero" />
                <details class="group text-[0.8125rem]">
                    <summary class="cursor-pointer font-semibold text-acao">Ver cada identidade</summary>
                    <div class="mt-3">
                        <BarrasHorizontais :itens="dados.genero.itens" legenda="Pessoas por identidade de gênero" />
                    </div>
                </details>
            </QuadroPainel>

            <QuadroPainel titulo="Raça/cor" :descricao="`Autodeclarada · pessoas negras (pretas e pardas): ${percentual(dados.negras.percentual)}${semResposta(dados.racaCor.semResposta)}`">
                <Rosca :itens="dados.racaCor.itens" legenda="Pessoas por raça/cor" :centro="{ valor: numero(dados.racaCor.respostas), rotulo: 'pessoas' }" />
            </QuadroPainel>

            <QuadroPainel titulo="Idade" :descricao="`Em anos completos, pela data de nascimento${semResposta(dados.idade.semResposta)}`">
                <Colunas :itens="dados.idade.faixas" legenda="Pessoas por faixa de idade" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Áreas de atuação" :descricao="`As mais marcadas; cada pessoa pode marcar várias${semResposta(dados.areas.semResposta)}`">
                <BarrasHorizontais :itens="areasMaisMarcadas" legenda="Pessoas pelas áreas de atuação mais marcadas" />
                <details v-if="areasMaisMarcadas.length && dados.areas.itens.length > areasMaisMarcadas.length" class="group text-[0.8125rem]">
                    <summary class="cursor-pointer font-semibold text-acao">Ver todas as áreas</summary>
                    <div class="mt-3">
                        <BarrasHorizontais :itens="dados.areas.itens" legenda="Pessoas por área de atuação" />
                    </div>
                </details>
            </QuadroPainel>

            <QuadroPainel titulo="Anos de atuação na cultura" descricao="Experiência declarada no cadastro">
                <Colunas :itens="dados.atuacao" legenda="Pessoas por anos de atuação na cultura" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Escolaridade" :descricao="`Na ordem dos níveis, do menor ao maior${semResposta(dados.escolaridade.semResposta)}`">
                <BarrasHorizontais :itens="dados.escolaridade.itens" legenda="Pessoas por escolaridade" cor="bg-serie-3" />
            </QuadroPainel>

            <QuadroPainel titulo="Renda familiar" :descricao="`Por mês, em salários mínimos${semResposta(dados.renda.semResposta)}`">
                <p class="text-[0.8125rem] text-tinta-suave">
                    <strong class="block text-[2rem] leading-none font-bold tracking-[-0.02em] text-home-tinta">{{ percentual(rendaAteUm) }}</strong>
                    vivem com até 1 salário mínimo (ou sem renda)
                </p>
                <BarraProporcao :itens="dados.renda.itens" legenda="Pessoas por faixa de renda familiar" escala="ordinal" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Pessoas com deficiência" descricao="Tipo de deficiência, entre quem declarou ter alguma">
                <AnelPercentual rotulo="Declararam deficiência" :total="dados.deficiencia.total" :base="dados.total" :percentual="dados.deficiencia.percentual" cor="stroke-serie-7" />
                <BarrasHorizontais :itens="dados.deficiencia.tipos.itens" legenda="Pessoas com deficiência por tipo" cor="bg-serie-7" />
            </QuadroPainel>

            <QuadroPainel titulo="Povos e comunidades tradicionais" descricao="Os mais citados, entre quem declarou pertencer a algum">
                <AnelPercentual rotulo="Pertencem a povos ou comunidades tradicionais" :total="dados.povos.total" :base="dados.total" :percentual="dados.povos.percentual" cor="stroke-serie-6" />
                <BarrasHorizontais :itens="dados.povos.itens.slice(0, 8)" legenda="Pessoas por povo ou comunidade tradicional" cor="bg-serie-6" />
            </QuadroPainel>
        </div>

        <div class="grid gap-3 @xl:grid-cols-2 @5xl:grid-cols-3">
            <AnelPercentual
                v-for="indicador in dados.indicadores"
                :key="indicador.valor"
                :rotulo="indicador.rotulo"
                :total="indicador.total"
                :base="dados.total"
                :percentual="indicador.percentual"
            />
        </div>

        <QuadroPainel titulo="Onde moram" descricao="Quem escolheu uma Prefeitura-Bairro mora em Salvador; as cidades são de quem mora fora">
            <div class="grid gap-6 @4xl:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] @4xl:items-center">
                <p class="text-[0.8125rem] text-tinta-suave">
                    <strong class="block text-[2.75rem] leading-none font-bold tracking-[-0.02em] text-home-tinta">{{ percentual(dados.moradia.percentual) }}</strong>
                    moram em Salvador ({{ numero(dados.moradia.salvador) }} de {{ numero(dados.total) }})
                </p>
                <BarrasHorizontais :itens="dados.moradia.cidades" legenda="Cidades de quem mora fora de Salvador" cor="bg-serie-2" />
            </div>
        </QuadroPainel>
    </SecaoPainel>
</template>
