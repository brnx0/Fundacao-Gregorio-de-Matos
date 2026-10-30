<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaPrefeiturasBairroIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaPrefeiturasBairroIndexProps>();

// Caminhos das rotas retaguarda.prefeituras-bairro.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/prefeituras-bairro';

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
    <PaginaRetaguarda titulo="Prefeituras-Bairro" descricao="As Prefeituras-Bairro de Salvador e os bairros de cada uma, usados nos cadastros do portal.">
        <Head title="Prefeituras-Bairro · Retaguarda" />

        <template #acoes>
            <Link :href="`${BASE}/create`" class="botao-faixa">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Nova Prefeitura-Bairro
            </Link>
        </template>

        <PainelLista
            legenda="Prefeituras-Bairro"
            :links="prefeituras.links"
            :total="prefeituras.total"
            :de="prefeituras.from"
            :ate="prefeituras.to"
            vazio="Nenhuma Prefeitura-Bairro encontrada."
        >
            <template #filtros>
            <form
                    role="search"
                    aria-label="Buscar Prefeituras-Bairro"
                    class="grid gap-3 @3xl:grid-cols-[minmax(0,28rem)_auto] @3xl:items-end"
                    @submit.prevent="filtrar"
                >
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pela Prefeitura-Bairro ou por um bairro</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="80" placeholder="Ex.: Pituba" class="campo-retaguarda" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Buscar</button>
                        <button v-if="filtros.busca" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Nº</th>
                    <th scope="col">Prefeitura-Bairro</th>
                    <th scope="col">Bairros</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="prefeitura in prefeituras.data" :key="prefeitura.id" class="align-middle">
                <td class="font-semibold whitespace-nowrap">{{ prefeitura.numero }}</td>
                <th scope="row" class="min-w-48 text-left font-semibold">{{ prefeitura.nome }}</th>
                <td class="tabular-nums">{{ prefeitura.totalBairros }}</td>
                <td>
                    <span class="selo" :class="prefeitura.ativo ? 'selo-positivo' : ''">{{ prefeitura.ativo ? 'Ativa' : 'Inativa' }}</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`${BASE}/${prefeitura.id}/edit`" class="acao-icone" data-dica="Editar">
                            <IconeAcao nome="editar" />
                            <span class="sr-only">Editar {{ prefeitura.nome }}</span>
                        </Link>
                        <Link :href="`${BASE}/${prefeitura.id}/ativo`" method="patch" as="button" preserve-scroll class="acao-icone" :data-dica="prefeitura.ativo ? 'Desativar' : 'Ativar'">
                            <IconeAcao :nome="prefeitura.ativo ? 'desativar' : 'ativar'" />
                            <span class="sr-only">{{ prefeitura.ativo ? 'Desativar' : 'Ativar' }} {{ prefeitura.nome }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
