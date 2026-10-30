<script setup lang="ts">
import { computed } from 'vue';
import type { IndicadorPainel, PainelVisaoGeral } from '../../../../types';
import AneisConcentricos from '../AneisConcentricos.vue';
import CartaoIndicador from '../CartaoIndicador.vue';
import { CALOR } from '../cores';
import { mesCurto, numero, reaisCurto } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';

const props = defineProps<{
    dados: PainelVisaoGeral;
    atualizando?: boolean;
}>();

const mesAtual = computed<string>(() => mesCurto(props.dados.meses.at(-1) ?? ''));
const periodo = computed<string>(() => `de ${mesCurto(props.dados.meses[0] ?? '')} a ${mesAtual.value}`);

/** Rodapé dos cartões com série: quanto entrou no último mês. */
const noMes = (indicador: IndicadorPainel): string => `+${numero(indicador.serie.at(-1) ?? 0)} em ${mesAtual.value}`;
const descricaoDaSerie = (indicador: IndicadorPainel): string => `Por mês, ${periodo.value}: ${indicador.serie.map(numero).join(', ')}`;

// Etapas do funil em ordem: um tom só, do mais forte (primeira etapa) ao mais claro.
const funil = computed(() =>
    props.dados.funil.map((etapa, indice) => ({ ...etapa, cor: CALOR[6 - indice].fundo, traco: CALOR[6 - indice].traco })),
);

const ICONES = {
    pessoas: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
    proponentes: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
    patrocinadores: 'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    organizacoes: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M8 7h4M8 11h4M8 15h4M2 21h20',
    inscricoes: 'M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 14l2 2 4-4',
    editais: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    investimento: 'M2 6h20v12H2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 12h.01M18 12h.01',
    avaliadores: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 11-5.7M16 18l2 2 4-4',
};
</script>

<template>
    <SecaoPainel
        id="visao-geral"
        titulo="Visão geral"
        :descricao="`Totais no filtro; os minigráficos mostram os meses ${periodo}`"
        icone="M3 3v18h18M7 14l4-4 4 4 5-5"
        :atualizando="atualizando"
    >
        <div class="grid gap-4 @6xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <QuadroPainel titulo="Funil do cadastro" descricao="De quem entrou pelo gov.br a quem enviou inscrição. Aqui só o filtro de período vale.">
                <AneisConcentricos
                    :itens="funil"
                    legenda="Funil do cadastro: pessoas em cada etapa"
                    :centro="{ valor: numero(dados.funil[0]?.total ?? 0), rotulo: 'pessoas entraram pelo gov.br' }"
                />
            </QuadroPainel>

            <div class="grid gap-4 @xl:grid-cols-2 @7xl:grid-cols-4">
                <CartaoIndicador
                    rotulo="Pessoas cadastradas"
                    :valor="numero(dados.indicadores.pessoas.total)"
                    :icone="ICONES.pessoas"
                    :serie="dados.indicadores.pessoas.serie"
                    :descricao-da-serie="descricaoDaSerie(dados.indicadores.pessoas)"
                    :rodape="noMes(dados.indicadores.pessoas)"
                    href="/retaguarda/proponentes"
                />
                <CartaoIndicador
                    rotulo="Proponentes"
                    :valor="numero(dados.indicadores.proponentes.total)"
                    :icone="ICONES.proponentes"
                    :serie="dados.indicadores.proponentes.serie"
                    :descricao-da-serie="descricaoDaSerie(dados.indicadores.proponentes)"
                    :rodape="noMes(dados.indicadores.proponentes)"
                    href="/retaguarda/proponentes?tipo=proponente"
                />
                <CartaoIndicador
                    rotulo="Patrocinadores"
                    :valor="numero(dados.indicadores.patrocinadores.total)"
                    :icone="ICONES.patrocinadores"
                    :serie="dados.indicadores.patrocinadores.serie"
                    :descricao-da-serie="descricaoDaSerie(dados.indicadores.patrocinadores)"
                    :rodape="noMes(dados.indicadores.patrocinadores)"
                    href="/retaguarda/proponentes?tipo=patrocinador"
                />
                <CartaoIndicador
                    rotulo="Organizações"
                    :valor="numero(dados.indicadores.organizacoes.total)"
                    :icone="ICONES.organizacoes"
                    :serie="dados.indicadores.organizacoes.serie"
                    :descricao-da-serie="descricaoDaSerie(dados.indicadores.organizacoes)"
                    :rodape="noMes(dados.indicadores.organizacoes)"
                />
                <CartaoIndicador
                    rotulo="Inscrições enviadas"
                    :valor="numero(dados.indicadores.inscricoes.total)"
                    :icone="ICONES.inscricoes"
                    :serie="dados.indicadores.inscricoes.serie"
                    :descricao-da-serie="descricaoDaSerie(dados.indicadores.inscricoes)"
                    :rodape="noMes(dados.indicadores.inscricoes)"
                />
                <CartaoIndicador
                    rotulo="Editais abertos"
                    :valor="numero(dados.editais.abertos)"
                    :icone="ICONES.editais"
                    :rodape="`${numero(dados.editais.emBreve)} em breve no portal`"
                    href="/retaguarda/editais?situacao=aberto"
                />
                <CartaoIndicador
                    rotulo="Investimento oferecido"
                    :valor="reaisCurto(dados.investimento.centavos)"
                    :icone="ICONES.investimento"
                    :rodape="`${numero(dados.investimento.vagas)} vagas nos editais publicados`"
                    href="/retaguarda/editais"
                />
                <CartaoIndicador
                    rotulo="Avaliadores ativos"
                    :valor="numero(dados.avaliadores.ativos)"
                    :icone="ICONES.avaliadores"
                    :rodape="`${numero(dados.avaliadores.total)} no cadastro, com os inativos`"
                    href="/retaguarda/avaliadores"
                />
            </div>
        </div>
    </SecaoPainel>
</template>
