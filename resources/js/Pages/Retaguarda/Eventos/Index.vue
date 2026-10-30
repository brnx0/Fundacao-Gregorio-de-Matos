<script setup lang="ts">
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ConfirmarExclusao from '../../../Components/Retaguarda/ConfirmarExclusao.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaEventosIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaEventosIndexProps>();

// Caminhos das rotas retaguarda.eventos.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/eventos';

const filtros = reactive({ ...props.filtros });

// Só os filtros preenchidos vão para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    Object.assign(filtros, { busca: '', publicado: '' });
    filtrar();
};

</script>

<template>
    <PaginaRetaguarda titulo="Eventos" descricao="A agenda cultural exibida no portal. Só os publicados aparecem para o público.">
        <Head title="Eventos · Retaguarda" />

        <template #acoes>
            <Link :href="`${BASE}/create`" class="botao-faixa">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Novo evento
            </Link>
        </template>

        <PainelLista
            legenda="Eventos"
            :links="eventos.links"
            :total="eventos.total"
            :de="eventos.from"
            :ate="eventos.to"
            vazio="Nenhum evento encontrado."
        >
            <template #filtros>
            <form role="search" aria-label="Filtrar eventos" class="grid gap-3 @3xl:grid-cols-[1fr_12rem_auto] @3xl:items-end" @submit.prevent="filtrar">
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo título</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Parte do título" class="campo-retaguarda" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="publicado" class="rotulo-retaguarda">Publicação</label>
                        <select id="publicado" v-model="filtros.publicado" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todos</option>
                            <option value="sim">Publicados</option>
                            <option value="nao">Não publicados</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Filtrar</button>
                        <button v-if="filtros.busca || filtros.publicado" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Título e local</th>
                    <th scope="col">Data</th>
                    <th scope="col">Publicado</th>
                    <th scope="col">Imagem</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="evento in eventos.data" :key="evento.slug" class="align-middle">
                <th scope="row" class="min-w-56 text-left font-normal">
                    <span class="block font-semibold">{{ evento.titulo }}</span>
                    <span v-if="evento.local" class="block text-[0.8125rem] text-tinta-suave">{{ evento.local }}</span>
                </th>
                <td class="whitespace-nowrap tabular-nums">{{ evento.data }}</td>
                <td>
                    <span class="selo" :class="evento.publicado ? 'selo-positivo' : ''">{{ evento.publicado ? 'Publicado' : 'Não publicado' }}</span>
                </td>
                <td>
                    <span v-if="evento.temImagem">Sim</span>
                    <span v-else class="text-tinta-suave">Não</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link
                            :href="`${BASE}/${evento.slug}/publicacao`"
                            method="patch"
                            as="button"
                            preserve-scroll
                            class="acao-icone"
                            :data-dica="evento.publicado ? 'Ocultar publicação' : 'Publicar'"
                        >
                            <IconeAcao :nome="evento.publicado ? 'ocultar' : 'publicar'" />
                            <span class="sr-only">{{ evento.publicado ? 'Ocultar publicação de' : 'Publicar' }} {{ evento.titulo }}</span>
                        </Link>
                        <Link :href="`${BASE}/${evento.slug}/edit`" class="acao-icone" data-dica="Editar">
                            <IconeAcao nome="editar" />
                            <span class="sr-only">Editar {{ evento.titulo }}</span>
                        </Link>
                        <ConfirmarExclusao
                            :url="`${BASE}/${evento.slug}`"
                            :nome="evento.titulo"
                            aviso="O evento sai do portal e da lista. A imagem continua guardada."
                            icone
                        />
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
