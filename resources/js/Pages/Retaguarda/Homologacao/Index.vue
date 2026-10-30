<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaHomologacaoIndexProps, SituacaoHomologacao } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaHomologacaoIndexProps>();

// Caminhos das rotas retaguarda.homologacao.index e retaguarda.homologacao.show (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/homologacao';

const filtros = reactive({ edital: props.filtros.edital ?? '', situacao: props.filtros.situacao ?? '', busca: props.filtros.busca ?? '' });

// Só os filtros preenchidos vão para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    filtros.edital = '';
    filtros.situacao = '';
    filtros.busca = '';
    filtrar();
};

/** Homologada em verde; em recurso e sem decisão pedem atenção; não homologada e recurso negado ficam no cinza. */
const classeDoSelo = (homologacao: SituacaoHomologacao | null): string => {
    if (homologacao === 'homologada') {
        return 'selo-positivo';
    }

    return homologacao === null || homologacao === 'em_recurso' ? 'selo-atencao' : '';
};
</script>

<template>
    <PaginaRetaguarda titulo="Homologação" descricao="Inscrições finalizadas, para conferir e decidir. Recursos e inscrições sem decisão aparecem primeiro.">
        <Head title="Homologação · Retaguarda" />

        <PainelLista
            legenda="Inscrições para homologação"
            :links="inscricoes.links"
            :total="inscricoes.total"
            :de="inscricoes.from"
            :ate="inscricoes.to"
            vazio="Nenhuma inscrição encontrada."
        >
            <template #filtros>
                <form
                    role="search"
                    aria-label="Filtrar inscrições"
                    class="grid gap-3 @2xl:grid-cols-2 @2xl:items-end @5xl:grid-cols-[1fr_16rem_12rem_auto]"
                    @submit.prevent="filtrar"
                >
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo número ou pelo proponente</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Número da inscrição ou parte do nome" class="campo-retaguarda" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="edital" class="rotulo-retaguarda">Edital</label>
                        <select id="edital" v-model="filtros.edital" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todos</option>
                            <option v-for="opcao in editais" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="situacao" class="rotulo-retaguarda">Situação</label>
                        <select id="situacao" v-model="filtros.situacao" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todas</option>
                            <option v-for="opcao in situacoes" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Filtrar</button>
                        <button v-if="filtros.busca || filtros.edital || filtros.situacao" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Inscrição</th>
                    <th scope="col">Proponente</th>
                    <th scope="col">Edital</th>
                    <th scope="col">Finalizada em</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="inscricao in inscricoes.data" :key="inscricao.id" class="align-middle">
                <th scope="row" class="text-left font-semibold whitespace-nowrap tabular-nums">{{ inscricao.numero }}</th>
                <td class="min-w-56">
                    <span class="block font-semibold">{{ inscricao.proponente }}</span>
                    <span class="block text-[0.8125rem] text-tinta-suave tabular-nums">{{ inscricao.documento }}</span>
                </td>
                <td class="min-w-48">
                    <span class="block">{{ inscricao.edital }}</span>
                    <span v-if="inscricao.categoria" class="block text-[0.8125rem] text-tinta-suave">{{ inscricao.categoria }}</span>
                </td>
                <td class="whitespace-nowrap tabular-nums">{{ inscricao.finalizadaEm }}</td>
                <td>
                    <span class="selo" :class="classeDoSelo(inscricao.homologacao)">{{ inscricao.situacaoRotulo }}</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end">
                        <Link :href="`${BASE}/${inscricao.id}`" class="acao-icone" data-dica="Conferir">
                            <IconeAcao nome="ver" />
                            <span class="sr-only">Conferir a inscrição {{ inscricao.numero }} de {{ inscricao.proponente }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
