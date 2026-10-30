<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaProponentesIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaProponentesIndexProps>();

// Caminhos das rotas retaguarda.proponentes.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/proponentes';

const filtros = reactive({ ...props.filtros });

// Só os filtros preenchidos vão para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    filtros.busca = '';
    filtros.situacao = '';
    filtros.tipo = '';
    filtrar();
};

</script>

<template>
    <PaginaRetaguarda titulo="Proponentes" descricao="Cadastros feitos no portal, só para consulta.">
        <Head title="Proponentes · Retaguarda" />

        <PainelLista
            legenda="Proponentes"
            :links="proponentes.links"
            :total="proponentes.total"
            :de="proponentes.from"
            :ate="proponentes.to"
            vazio="Nenhum cadastro encontrado."
        >
            <template #filtros>
                <form
                    role="search"
                    aria-label="Filtrar proponentes"
                    class="grid gap-3 @2xl:grid-cols-2 @2xl:items-end @5xl:grid-cols-[1fr_12rem_12rem_auto]"
                    @submit.prevent="filtrar"
                >
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo nome, e-mail ou CPF</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Parte do nome, e-mail ou CPF completo" class="campo-retaguarda" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="situacao" class="rotulo-retaguarda">Situação</label>
                        <select id="situacao" v-model="filtros.situacao" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todas</option>
                            <option v-for="opcao in situacoes" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="tipo" class="rotulo-retaguarda">Tipo</label>
                        <select id="tipo" v-model="filtros.tipo" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todos</option>
                            <option v-for="opcao in tiposCadastro" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Filtrar</button>
                        <button v-if="filtros.busca || filtros.situacao || filtros.tipo" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">CPF</th>
                    <th scope="col">Cidade</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Cadastrado em</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="proponente in proponentes.data" :key="proponente.id" class="align-middle">
                <th scope="row" class="min-w-56 text-left font-normal">
                    <span class="block font-semibold">{{ proponente.nome }}</span>
                    <span v-if="proponente.nomeSocial" class="block text-[0.8125rem] text-tinta-suave">Nome social: {{ proponente.nomeSocial }}</span>
                    <span v-if="proponente.email" class="block text-[0.8125rem] text-tinta-suave">{{ proponente.email }}</span>
                </th>
                <td class="whitespace-nowrap tabular-nums">{{ proponente.cpf }}</td>
                <td>{{ proponente.cidade ?? '—' }}</td>
                <td>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="tipo in proponente.tipos" :key="tipo" class="selo selo-etiqueta">{{ tipo }}</span>
                        <span v-if="!proponente.finalizado" class="selo selo-atencao">Em andamento</span>
                    </div>
                </td>
                <td class="whitespace-nowrap tabular-nums">{{ proponente.cadastradoEm ?? '—' }}</td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end">
                        <Link :href="`${BASE}/${proponente.id}`" class="acao-icone" data-dica="Ver cadastro">
                            <IconeAcao nome="ver" />
                            <span class="sr-only">Ver cadastro de {{ proponente.nome }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
