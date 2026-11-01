<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaAvaliacaoIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliacaoIndexProps>();

// Caminhos das rotas retaguarda.avaliacao.index e retaguarda.avaliacao.avaliadores.index (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/avaliacao';

const filtros = reactive({ busca: props.filtros.busca ?? '' });

// Só a busca preenchida vai para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    router.get(BASE, filtros.busca !== '' ? { busca: filtros.busca } : {}, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    filtros.busca = '';
    filtrar();
};
</script>

<template>
    <PaginaRetaguarda titulo="Avaliação" descricao="Escolha o edital para incluir os avaliadores, distribuir as inscrições homologadas e acompanhar as notas.">
        <Head title="Avaliação · Retaguarda" />

        <PainelLista
            legenda="Editais com inscrições para avaliação"
            :links="editais.links"
            :total="editais.total"
            :de="editais.from"
            :ate="editais.to"
            vazio="Nenhum edital com inscrição homologada."
        >
            <template #filtros>
                <form role="search" aria-label="Filtrar editais" class="grid gap-3 @2xl:grid-cols-[1fr_auto] @2xl:items-end" @submit.prevent="filtrar">
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo título do edital</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Parte do título" class="campo-retaguarda" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Filtrar</button>
                        <button v-if="filtros.busca" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Edital</th>
                    <th scope="col">Período de avaliação</th>
                    <th scope="col" class="text-right">Homologadas</th>
                    <th scope="col" class="text-right">Sem avaliador</th>
                    <th scope="col" class="text-right">Concluídas</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="edital in editais.data" :key="edital.id" class="align-middle">
                <th scope="row" class="min-w-56 text-left font-semibold">
                    <Link :href="`${BASE}/editais/${edital.slug}/avaliadores`" class="hover:underline">{{ edital.titulo }}</Link>
                </th>
                <td class="min-w-48">
                    <span v-for="(periodo, indice) in edital.periodos" :key="indice" class="block tabular-nums">{{ periodo }}</span>
                    <span v-if="edital.emAvaliacao" class="selo selo-positivo mt-1">Em andamento</span>
                </td>
                <td class="text-right tabular-nums">{{ edital.homologadas }}</td>
                <td class="text-right tabular-nums">
                    <span v-if="edital.semAvaliador > 0" class="selo selo-atencao">{{ edital.semAvaliador }}</span>
                    <span v-else>0</span>
                </td>
                <td class="text-right tabular-nums">{{ edital.concluidas }}</td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end">
                        <Link :href="`${BASE}/editais/${edital.slug}/avaliadores`" class="acao-icone" data-dica="Abrir a avaliação">
                            <IconeAcao nome="ver" />
                            <span class="sr-only">Abrir a avaliação do edital {{ edital.titulo }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
