<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaAreasAtuacaoIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAreasAtuacaoIndexProps>();

// Caminhos das rotas retaguarda.areas-atuacao.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/areas-atuacao';

const filtros = reactive({ ...props.filtros });

// Só a busca preenchida vai para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    filtros.busca = '';
    filtrar();
};

</script>

<template>
    <PaginaRetaguarda titulo="Áreas de atuação" descricao="Opções de área de atuação oferecidas nos cadastros do portal.">
        <Head title="Áreas de atuação · Retaguarda" />

        <template #acoes>
            <Link :href="`${BASE}/create`" class="botao-faixa">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Nova área de atuação
            </Link>
        </template>

        <PainelLista
            legenda="Áreas de atuação"
            :links="areas.links"
            :total="areas.total"
            :de="areas.from"
            :ate="areas.to"
            vazio="Nenhuma área de atuação encontrada."
        >
            <template #filtros>
            <form
                    role="search"
                    aria-label="Buscar áreas de atuação"
                    class="grid gap-3 @3xl:grid-cols-[minmax(0,28rem)_auto] @3xl:items-end"
                    @submit.prevent="filtrar"
                >
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo nome</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="100" placeholder="Ex.: Teatro" class="campo-retaguarda" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Buscar</button>
                        <button v-if="filtros.busca" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Área de atuação</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="area in areas.data" :key="area.id" class="align-middle">
                <th scope="row" class="min-w-48 text-left font-semibold">{{ area.nome }}</th>
                <td>
                    <span class="selo" :class="area.ativo ? 'selo-positivo' : ''">{{ area.ativo ? 'Ativa' : 'Inativa' }}</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`${BASE}/${area.id}/edit`" class="acao-icone" data-dica="Editar">
                            <IconeAcao nome="editar" />
                            <span class="sr-only">Editar {{ area.nome }}</span>
                        </Link>
                        <Link :href="`${BASE}/${area.id}/ativo`" method="patch" as="button" preserve-scroll class="acao-icone" :data-dica="area.ativo ? 'Desativar' : 'Ativar'">
                            <IconeAcao :nome="area.ativo ? 'desativar' : 'ativar'" />
                            <span class="sr-only">{{ area.ativo ? 'Desativar' : 'Ativar' }} {{ area.nome }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
