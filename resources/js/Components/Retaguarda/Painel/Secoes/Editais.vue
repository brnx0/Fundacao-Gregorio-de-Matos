<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { PainelEditais } from '../../../../types';
import AneisConcentricos from '../AneisConcentricos.vue';
import AnelPercentual from '../AnelPercentual.vue';
import BarraProporcao from '../BarraProporcao.vue';
import BarrasHorizontais from '../BarrasHorizontais.vue';
import CartaoIndicador from '../CartaoIndicador.vue';
import { numero, percentual, reais, reaisCurto } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';

const props = defineProps<{
    dados: PainelEditais;
    atualizando?: boolean;
}>();

// Situação do edital com as cores que a lista de editais já usa (ponto verde, âmbar e cinza).
const CORES_DA_SITUACAO: Record<string, { cor: string; traco: string }> = {
    aberto: { cor: 'bg-situacao-aberto', traco: 'stroke-situacao-aberto' },
    em_breve: { cor: 'bg-situacao-em-breve', traco: 'stroke-situacao-em-breve' },
    encerrado: { cor: 'bg-situacao-encerrado', traco: 'stroke-situacao-encerrado' },
    sem_periodo: { cor: 'bg-serie-neutra', traco: 'stroke-serie-neutra' },
};

const situacoes = computed(() => props.dados.situacoes.map((situacao) => ({ ...situacao, ...CORES_DA_SITUACAO[situacao.valor] })));
const porVaga = computed<number>(() => (props.dados.vagas > 0 ? props.dados.centavos / props.dados.vagas : 0));
const porCategoria = computed(() =>
    props.dados.porCategoria.map((categoria) => ({ rotulo: `${categoria.rotulo} (${numero(categoria.editais)})`, total: categoria.centavos, percentual: 0 })),
);

const ICONES = {
    publicados: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    valor: 'M2 6h20v12H2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 12h.01M18 12h.01',
    vagas: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6',
    media: 'M3 3v18h18M7 16V9M12 16V5M17 16v-4',
};
</script>

<template>
    <SecaoPainel
        id="editais"
        titulo="Editais e investimento"
        descricao="Editais publicados, o valor oferecido (valor × vagas de cada categoria) e a procura de cada um"
        icone="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"
        :atualizando="atualizando"
    >
        <div class="grid gap-4 @xl:grid-cols-2 @6xl:grid-cols-4">
            <CartaoIndicador
                rotulo="Editais publicados"
                :valor="numero(dados.publicados)"
                :icone="ICONES.publicados"
                :rodape="`${numero(dados.total - dados.publicados)} ainda não publicados`"
                href="/retaguarda/editais"
            />
            <CartaoIndicador
                rotulo="Valor oferecido"
                :valor="reaisCurto(dados.centavos)"
                :icone="ICONES.valor"
                :rodape="dados.semValor > 0 ? `${reais(dados.centavos)} · ${numero(dados.semValor)} categorias sem valor ou vagas` : reais(dados.centavos)"
            />
            <CartaoIndicador rotulo="Vagas oferecidas" :valor="numero(dados.vagas)" :icone="ICONES.vagas" rodape="Somando as categorias e os itens" />
            <CartaoIndicador rotulo="Valor médio por vaga" :valor="reaisCurto(porVaga)" :icone="ICONES.media" :rodape="reais(porVaga)" />
        </div>

        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Situação dos editais publicados" descricao="Pelo período de inscrição, hoje">
                <AneisConcentricos
                    :itens="situacoes"
                    legenda="Editais publicados por situação"
                    :centro="{ valor: numero(dados.publicados), rotulo: 'editais publicados' }"
                />
            </QuadroPainel>
            <QuadroPainel titulo="Investimento por categoria" descricao="Pela categoria da vitrine do edital, com o número de editais entre parênteses">
                <BarrasHorizontais :itens="porCategoria" legenda="Valor oferecido por categoria do edital" :formatar="reaisCurto" :com-percentual="false" cor="bg-serie-6" />
            </QuadroPainel>
        </div>

        <div class="grid gap-4 @5xl:grid-cols-3">
            <QuadroPainel titulo="Cotas" descricao="Editais publicados com reserva para cada grupo">
                <div class="grid gap-3">
                    <AnelPercentual
                        v-for="cota in dados.cotas"
                        :key="cota.valor"
                        :rotulo="cota.rotulo"
                        :total="cota.total"
                        :base="dados.publicados"
                        :percentual="cota.percentual"
                        unidade="editais"
                        cor="stroke-serie-5"
                    />
                </div>
            </QuadroPainel>
            <QuadroPainel titulo="Quem pode se inscrever" descricao="Editais publicados que aceitam cada natureza">
                <BarrasHorizontais :itens="dados.naturezas" legenda="Editais publicados por natureza aceita" cor="bg-serie-7" />
                <AnelPercentual
                    rotulo="Aceitam coletivos"
                    :total="dados.coletivos.total"
                    :base="dados.publicados"
                    :percentual="dados.coletivos.percentual"
                    unidade="editais"
                    cor="stroke-serie-7"
                />
            </QuadroPainel>
            <QuadroPainel titulo="Tipo" descricao="Editais publicados">
                <BarraProporcao :itens="dados.tipos" legenda="Editais publicados por tipo" />
            </QuadroPainel>
        </div>

        <QuadroPainel titulo="Procura por edital" descricao="Os mais procurados no filtro: inscrições enviadas, rascunhos e quantas inscrições há por vaga">
            <p v-if="dados.demanda.length === 0" class="py-6 text-center text-[0.8125rem] text-tinta-suave">Nenhum edital no filtro.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-[0.8125rem]">
                    <caption class="sr-only">Procura por edital</caption>
                    <thead>
                        <tr class="border-b border-hairline text-left text-[0.75rem] text-tinta-suave">
                            <th scope="col" class="py-2 pr-3 font-semibold">Edital</th>
                            <th scope="col" class="px-3 py-2 font-semibold">Situação</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Vagas</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Enviadas</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Rascunhos</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Por vaga</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Valor oferecido</th>
                            <th scope="col" class="py-2 pl-3 text-right font-semibold">Com cota</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="edital in dados.demanda" :key="edital.id">
                            <th scope="row" class="max-w-72 py-2.5 pr-3 text-left font-semibold text-home-tinta">
                                <Link :href="`/retaguarda/editais?busca=${encodeURIComponent(edital.titulo)}`" class="hover:underline">{{ edital.titulo }}</Link>
                                <span v-if="!edital.publicado" class="ml-1 text-[0.75rem] font-normal text-tinta-suave">(não publicado)</span>
                            </th>
                            <td class="px-3 py-2.5 whitespace-nowrap text-tinta-suave">
                                <span class="inline-flex items-center gap-1.5">
                                    <span aria-hidden="true" class="size-2 rounded-full" :class="CORES_DA_SITUACAO[edital.situacao].cor" />
                                    {{ edital.situacaoRotulo }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ numero(edital.vagas) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold text-home-tinta tabular-nums">{{ numero(edital.enviadas) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ numero(edital.rascunhos) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ edital.porVaga === null ? '—' : numero(edital.porVaga) }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">{{ reaisCurto(edital.centavos) }}</td>
                            <td class="py-2.5 pl-3 text-right tabular-nums">{{ percentual(edital.cotistas) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </QuadroPainel>
    </SecaoPainel>
</template>
