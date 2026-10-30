<script setup lang="ts">
import { reactive } from 'vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaUsuariosIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaUsuariosIndexProps>();

// Caminhos das rotas retaguarda.usuarios.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/usuarios';

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
    <PaginaRetaguarda titulo="Usuários" descricao="Quem entra na retaguarda e com quais papéis. Usuário não se exclui: desative o acesso.">
        <Head title="Usuários · Retaguarda" />

        <template #acoes>
            <Link :href="`${BASE}/create`" class="botao-faixa">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Novo usuário
            </Link>
        </template>

        <PainelLista
            legenda="Usuários"
            :links="usuarios.links"
            :total="usuarios.total"
            :de="usuarios.from"
            :ate="usuarios.to"
            vazio="Nenhum usuário encontrado."
        >
            <template #filtros>
                <form role="search" aria-label="Buscar usuários" class="grid gap-3 @3xl:grid-cols-[minmax(0,28rem)_auto] @3xl:items-end" @submit.prevent="filtrar">
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo nome ou e-mail</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Parte do nome ou do e-mail" class="campo-retaguarda" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @3xl:flex-none">Buscar</button>
                        <button v-if="filtros.busca" type="button" class="botao-secundario flex-1 @3xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">CPF</th>
                    <th scope="col">Papéis</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="usuario in usuarios.data" :key="usuario.id" class="align-middle">
                <th scope="row" class="min-w-48 text-left font-normal">
                    <span class="font-semibold">{{ usuario.nome }}</span>
                    <span v-if="usuario.ehVoce" class="selo selo-etiqueta ml-2">você</span>
                </th>
                <td class="whitespace-nowrap">{{ usuario.email }}</td>
                <td class="whitespace-nowrap tabular-nums">{{ usuario.cpf }}</td>
                <td>{{ usuario.papeis.join(', ') }}</td>
                <td>
                    <span class="selo" :class="usuario.ativo ? 'selo-positivo' : ''">{{ usuario.ativo ? 'Ativo' : 'Inativo' }}</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`${BASE}/${usuario.id}/edit`" class="acao-icone" data-dica="Editar">
                            <IconeAcao nome="editar" />
                            <span class="sr-only">Editar {{ usuario.nome }}</span>
                        </Link>
                        <Link
                            :href="`${BASE}/${usuario.id}/ativo`"
                            method="patch"
                            as="button"
                            preserve-scroll
                            class="acao-icone"
                            :data-dica="usuario.ativo ? 'Desativar' : 'Ativar'"
                        >
                            <IconeAcao :nome="usuario.ativo ? 'desativar' : 'ativar'" />
                            <span class="sr-only">{{ usuario.ativo ? 'Desativar' : 'Ativar' }} {{ usuario.nome }}</span>
                        </Link>
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
