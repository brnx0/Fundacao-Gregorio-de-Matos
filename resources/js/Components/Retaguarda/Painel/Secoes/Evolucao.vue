<script setup lang="ts">
import type { PainelEvolucao } from '../../../../types';
import CalendarioDeCalor from '../CalendarioDeCalor.vue';
import EvolucaoMensal from '../EvolucaoMensal.vue';
import { dataCurta, numero } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';

defineProps<{
    dados: PainelEvolucao;
    atualizando?: boolean;
}>();
</script>

<template>
    <SecaoPainel
        id="evolucao"
        titulo="Evolução"
        descricao="Como os cadastros crescem e o ritmo das inscrições"
        icone="M4 6h16v14H4zM4 10h16M9 3v5M15 3v5"
        :atualizando="atualizando"
    >
        <QuadroPainel titulo="Novos cadastros por mês" descricao="Pelo mês em que a pessoa enviou o perfil, separado pelo tipo de cadastro escolhido">
            <EvolucaoMensal :meses="dados.meses" legenda="Novos cadastros por mês e tipo" />
        </QuadroPainel>

        <QuadroPainel
            titulo="Ritmo das inscrições"
            :descricao="`Inscrições enviadas por dia, de ${dataCurta(dados.ritmo.inicio)} a ${dataCurta(dados.ritmo.fim)}: ${numero(dados.ritmo.total)} no total; o dia mais cheio teve ${numero(dados.ritmo.maximo)}`"
        >
            <CalendarioDeCalor
                :inicio="dados.ritmo.inicio"
                :fim="dados.ritmo.fim"
                :dias="dados.ritmo.dias"
                :maximo="dados.ritmo.maximo"
                legenda="Inscrições enviadas por mês"
            />
        </QuadroPainel>
    </SecaoPainel>
</template>
