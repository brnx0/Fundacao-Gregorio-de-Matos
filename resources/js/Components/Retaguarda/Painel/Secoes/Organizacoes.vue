<script setup lang="ts">
import type { PainelOrganizacoes } from '../../../../types';
import BarrasHorizontais from '../BarrasHorizontais.vue';
import Colunas from '../Colunas.vue';
import { numero, percentual, quantos } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import Rosca from '../Rosca.vue';
import SecaoPainel from '../SecaoPainel.vue';

defineProps<{
    dados: PainelOrganizacoes;
    atualizando?: boolean;
}>();
</script>

<template>
    <SecaoPainel
        id="organizacoes"
        titulo="Organizações"
        descricao="Pessoas jurídicas e MEI cadastradas. Os filtros de público e de território não valem aqui: a organização não os informa."
        icone="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M8 7h4M8 11h4M8 15h4M2 21h20"
        :atualizando="atualizando"
    >
        <template #extra>
            <span class="selo self-start @3xl:self-auto">{{ quantos(dados.total, 'organização', 'organizações') }} no filtro</span>
        </template>

        <div class="grid gap-4 @5xl:grid-cols-3">
            <QuadroPainel titulo="Natureza" descricao="MEI, sem ou com fins lucrativos, como na inscrição">
                <Rosca :itens="dados.naturezas" legenda="Organizações por natureza" :centro="{ valor: numero(dados.total), rotulo: 'organizações' }" />
            </QuadroPainel>
            <QuadroPainel
                titulo="Porte"
                :descricao="dados.porte.semResposta > 0 ? `Sobre as que informaram · ${numero(dados.porte.semResposta)} sem porte informado` : 'Sobre as que informaram'"
            >
                <BarrasHorizontais :itens="dados.porte.itens" legenda="Organizações por porte" cor="bg-serie-3" />
            </QuadroPainel>
            <QuadroPainel titulo="Natureza jurídica" descricao="Grupo da tabela da Receita Federal">
                <BarrasHorizontais :itens="dados.grupos" legenda="Organizações por grupo de natureza jurídica" cor="bg-serie-7" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Tempo de existência" descricao="Anos desde a fundação, entre as que informaram a data">
                <Colunas :itens="dados.idade" legenda="Organizações por tempo de existência" />
            </QuadroPainel>
            <QuadroPainel titulo="Município da sede" descricao="Os outros municípios mais citados">
                <div class="grid gap-5 @4xl:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] @4xl:items-center">
                    <p class="text-[0.8125rem] text-tinta-suave">
                        <strong class="block text-[2.25rem] leading-none font-bold tracking-[-0.02em] text-home-tinta">{{ percentual(dados.municipios.percentual) }}</strong>
                        com sede em Salvador ({{ numero(dados.municipios.salvador) }})
                    </p>
                    <BarrasHorizontais :itens="dados.municipios.itens" legenda="Organizações por município da sede, fora Salvador" cor="bg-serie-2" />
                </div>
            </QuadroPainel>
        </div>
    </SecaoPainel>
</template>
