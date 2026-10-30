<script setup lang="ts">
import type { PainelInscricoes } from '../../../../types';
import AnelPercentual from '../AnelPercentual.vue';
import BarrasHorizontais from '../BarrasHorizontais.vue';
import CartaoIndicador from '../CartaoIndicador.vue';
import { numero, percentual } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';
import TabelaLeitor from '../TabelaLeitor.vue';

defineProps<{
    dados: PainelInscricoes;
    atualizando?: boolean;
}>();

const ICONES = {
    enviadas: 'M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 14l2 2 4-4',
    rascunhos: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
    reabertas: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5',
    conclusao: 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3',
};
</script>

<template>
    <SecaoPainel
        id="inscricoes"
        titulo="Inscrições"
        descricao="Quem se inscreve nos editais e quem fica pelo caminho. Enviadas contam pelo dia do envio; rascunhos, pelo dia em que começaram."
        icone="M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 14l2 2 4-4"
        :atualizando="atualizando"
    >
        <div class="grid gap-4 @xl:grid-cols-2 @6xl:grid-cols-4">
            <CartaoIndicador rotulo="Inscrições enviadas" :valor="numero(dados.enviadas)" :icone="ICONES.enviadas" rodape="Finalizadas pela pessoa" />
            <CartaoIndicador rotulo="Em rascunho" :valor="numero(dados.rascunhos)" :icone="ICONES.rascunhos" rodape="Começadas e ainda não enviadas" />
            <CartaoIndicador rotulo="Reabertas" :valor="numero(dados.reabertas)" :icone="ICONES.reabertas" rodape="Já enviadas, voltaram para correção" />
            <CartaoIndicador rotulo="Taxa de envio" :valor="percentual(dados.conclusao)" :icone="ICONES.conclusao" rodape="Das inscrições começadas, quantas foram enviadas" />
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel
                titulo="Quem se cadastra × quem se inscreve"
                descricao="Percentual de cada grupo entre as pessoas cadastradas e entre as que enviaram inscrição (cada pessoa conta uma vez)"
            >
                <ul aria-hidden="true" class="flex flex-wrap gap-x-4 gap-y-1 text-[0.75rem] text-tinta-suave">
                    <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-serie-1" />Cadastradas</li>
                    <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-serie-2" />Inscritas</li>
                </ul>
                <ul aria-hidden="true" class="flex flex-col gap-3">
                    <li
                        v-for="recorte in dados.comparacao"
                        :key="recorte.valor"
                        class="grid grid-cols-[minmax(0,42%)_minmax(0,1fr)] items-center gap-3 text-[0.8125rem] @xl:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]"
                    >
                        <span class="text-right leading-tight text-tinta-suave">{{ recorte.rotulo }}</span>
                        <span class="flex flex-col gap-1">
                            <span v-for="(valor, indice) in [recorte.cadastro, recorte.inscricao]" :key="indice" class="flex items-center gap-2">
                                <span class="relative h-2 min-w-0 flex-1 rounded-[4px] bg-trilho">
                                    <span class="absolute inset-y-0 left-0 rounded-r-[4px]" :class="indice === 0 ? 'bg-serie-1' : 'bg-serie-2'" :style="{ width: `${Math.min(valor, 100)}%` }" />
                                </span>
                                <span class="w-12 shrink-0 text-right font-semibold text-home-tinta tabular-nums">{{ percentual(valor) }}</span>
                            </span>
                        </span>
                    </li>
                </ul>
                <TabelaLeitor
                    legenda="Percentual de cada grupo entre cadastradas e inscritas"
                    :colunas="['Grupo', 'Entre as cadastradas', 'Entre as inscritas']"
                    :linhas="dados.comparacao.map((recorte) => [recorte.rotulo, percentual(recorte.cadastro), percentual(recorte.inscricao)])"
                />
            </QuadroPainel>

            <QuadroPainel titulo="Inscrições por Prefeitura-Bairro" descricao="Pelo território de quem enviou a inscrição">
                <BarrasHorizontais :itens="dados.territorios" legenda="Inscrições enviadas por Prefeitura-Bairro" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-3">
            <QuadroPainel titulo="Natureza de quem se inscreve" descricao="Inscrições enviadas">
                <BarrasHorizontais :itens="dados.natureza.itens" legenda="Inscrições enviadas por natureza" cor="bg-serie-3" />
            </QuadroPainel>
            <QuadroPainel titulo="Cotas pedidas" descricao="Inscrições enviadas que concorrem por cota">
                <div class="grid gap-3">
                    <AnelPercentual
                        v-for="cota in dados.cotas"
                        :key="cota.valor"
                        :rotulo="cota.rotulo"
                        :total="cota.total"
                        :base="dados.enviadas"
                        :percentual="cota.percentual"
                        unidade="inscrições"
                        cor="stroke-serie-5"
                    />
                </div>
            </QuadroPainel>
            <QuadroPainel titulo="Conferência dos documentos" descricao="Documentos das inscrições enviadas, pela situação da conferência">
                <BarrasHorizontais :itens="dados.documentos.itens" legenda="Documentos por situação da conferência" cor="bg-serie-7" />
            </QuadroPainel>
        </div>
    </SecaoPainel>
</template>
