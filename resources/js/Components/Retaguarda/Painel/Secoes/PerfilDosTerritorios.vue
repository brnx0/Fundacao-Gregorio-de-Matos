<script setup lang="ts">
import type { PainelEvolucao } from '../../../../types';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';
import TabelaDeCalor from '../TabelaDeCalor.vue';

defineProps<{
    /** Perfil de cada Prefeitura-Bairro: chega com a evolução, que o servidor calcula junto. */
    dados: PainelEvolucao['territorios'];
    /** Mínimo de pessoas para um território mostrar percentuais. */
    baseMinima: number;
    atualizando?: boolean;
}>();
</script>

<template>
    <SecaoPainel
        id="perfil-dos-territorios"
        titulo="Perfil dos territórios"
        descricao="Quem são as pessoas de cada Prefeitura-Bairro: os grupos do perfil, quem vive da cultura e quem já enviou inscrição"
        icone="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"
        :atualizando="atualizando"
    >
        <QuadroPainel
            titulo="Perfil por Prefeitura-Bairro"
            :descricao="`Percentual das pessoas de cada território em cada grupo; o tom mais forte é o maior da coluna. Com menos de ${baseMinima} pessoas, o percentual não aparece.`"
        >
            <TabelaDeCalor :colunas="dados.colunas" :linhas="dados.linhas" :base-minima="baseMinima" legenda="Perfil das pessoas por Prefeitura-Bairro" />
        </QuadroPainel>
    </SecaoPainel>
</template>
