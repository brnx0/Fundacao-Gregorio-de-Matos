<script setup lang="ts">
import { reactive } from 'vue';
import AbasDaAvaliacao from '../../../Components/Retaguarda/Avaliacao/AbasDaAvaliacao.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaAvaliacaoNotasProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliacaoNotasProps>();

// Caminhos das rotas retaguarda.avaliacao.index, retaguarda.avaliacao.notas.index e .show (o front não tem helper de
// rotas nomeadas).
const LISTA = '/retaguarda/avaliacao';
const BASE = `${LISTA}/editais/${props.edital.slug}/notas`;

const filtros = reactive({ situacao: props.filtros.situacao });

// Só o filtro preenchido vai para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    router.get(BASE, filtros.situacao === '' ? {} : { situacao: filtros.situacao }, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    filtros.situacao = '';
    filtrar();
};
</script>

<template>
    <PaginaRetaguarda
        :titulo="edital.titulo"
        descricao="Notas finais das avaliações concluídas. O que o avaliador ainda não concluiu não aparece."
        :trilha="[{ texto: edital.titulo }]"
    >
        <Head :title="`Notas · ${edital.titulo} · Avaliação · Retaguarda`" />

        <template #detalhe>
            <span v-for="(periodo, indice) in edital.periodos" :key="indice" class="selo-faixa">Avaliação: {{ periodo }}</span>
        </template>

        <template #acoes>
            <Link
                :href="LISTA"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M11 18l-6-6 6-6" />
                </svg>
                Voltar para os editais
            </Link>
        </template>

        <AbasDaAvaliacao :edital="edital.slug" atual="notas" />

        <PainelLista
            legenda="Notas das inscrições homologadas do edital"
            :links="inscricoes.links"
            :total="inscricoes.total"
            :de="inscricoes.from"
            :ate="inscricoes.to"
            vazio="Nenhuma inscrição encontrada."
        >
            <template #filtros>
                <form role="search" aria-label="Filtrar inscrições" class="grid gap-3 @2xl:grid-cols-[16rem_auto] @2xl:items-end" @submit.prevent="filtrar">
                    <div class="flex flex-col gap-1.5">
                        <label for="situacao" class="rotulo-retaguarda">Situação</label>
                        <select id="situacao" v-model="filtros.situacao" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todas</option>
                            <option v-for="opcao in situacoes" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button v-if="filtros.situacao" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Inscrição</th>
                    <th scope="col">Proponente</th>
                    <th scope="col">Categoria</th>
                    <th scope="col">Avaliador</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Nota final</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="inscricao in inscricoes.data" :key="inscricao.id" class="align-middle">
                <th scope="row" class="text-left font-semibold whitespace-nowrap tabular-nums">{{ inscricao.numero }}</th>
                <td class="min-w-56 font-semibold">{{ inscricao.proponente }}</td>
                <td class="min-w-40">{{ inscricao.categoria ?? '—' }}</td>
                <td class="min-w-40">{{ inscricao.avaliador ?? '—' }}</td>
                <td>
                    <span class="selo" :class="{ 'selo-atencao': inscricao.avaliador === null, 'selo-positivo': inscricao.situacao === 'concluida' }">
                        {{ inscricao.situacaoRotulo }}
                    </span>
                </td>
                <td class="font-semibold whitespace-nowrap tabular-nums">{{ inscricao.notaFinal ?? '—' }}</td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end">
                        <Link v-if="inscricao.situacao === 'concluida'" :href="`${BASE}/${inscricao.id}`" class="acao-icone" data-dica="Ver as notas">
                            <IconeAcao nome="ver" />
                            <span class="sr-only">Ver as notas da inscrição {{ inscricao.numero }} de {{ inscricao.proponente }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
