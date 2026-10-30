<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

type ItemMenu = { href: string; texto: string; icone: string };
type GrupoMenu = { id: string; texto: string; icone: string; itens: ItemMenu[] };

// Caminhos das rotas retaguarda.editais.index, retaguarda.eventos.index, retaguarda.vlibras.edit,
// retaguarda.proponentes.index, retaguarda.avaliadores.index, retaguarda.prefeituras-bairro.index, retaguarda.areas-atuacao.index,
// retaguarda.integracao-ia.edit, retaguarda.email.edit e retaguarda.usuarios.index (o front não tem helper de rotas nomeadas).
const portal: GrupoMenu = {
    id: 'menu-grupo-portal',
    texto: 'Portal',
    icone: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    itens: [
        {
            href: '/retaguarda/editais',
            texto: 'Editais',
            icone: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
        },
        {
            href: '/retaguarda/eventos',
            texto: 'Eventos',
            icone: 'M4 6h16v14H4zM4 10h16M9 3v5M15 3v5',
        },
        {
            href: '/retaguarda/vlibras',
            texto: 'VLibras',
            icone: 'M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15',
        },
    ],
};

// Cadastros de pessoas: os proponentes do portal (só consulta) e os avaliadores.
const cadastro: GrupoMenu = {
    id: 'menu-grupo-cadastro',
    texto: 'Cadastro',
    icone: 'M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 12h6M9 16h4',
    itens: [
        {
            href: '/retaguarda/proponentes',
            texto: 'Proponentes',
            icone: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
        },
        {
            href: '/retaguarda/avaliadores',
            texto: 'Avaliadores',
            icone: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 11-5.7M16 18l2 2 4-4',
        },
    ],
};

// Tabelas de apoio, usadas pela retaguarda e pelo portal.
const parametrizacao: GrupoMenu = {
    id: 'menu-grupo-parametrizacao',
    texto: 'Parametrização',
    icone: 'M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4',
    itens: [
        {
            href: '/retaguarda/prefeituras-bairro',
            texto: 'Prefeituras-Bairro',
            icone: 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        },
        {
            href: '/retaguarda/areas-atuacao',
            texto: 'Áreas de atuação',
            icone: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        },
        {
            href: '/retaguarda/integracao-ia',
            texto: 'Integração com IA',
            icone: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 15v4M17 17h4M5 3v3M3.5 4.5h3',
        },
        {
            href: '/retaguarda/email',
            texto: 'E-mail',
            icone: 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 7l9 6 9-6',
        },
    ],
};

const grupos: GrupoMenu[] = [portal, cadastro, parametrizacao];

const usuarios: ItemMenu = {
    href: '/retaguarda/usuarios',
    texto: 'Usuários',
    icone: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6',
};

const page = usePage();
const atual = (href: string): boolean => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
const grupoAtivo = (grupo: GrupoMenu): boolean => grupo.itens.some((item) => atual(item.href));

// Cada grupo abre sozinho quando a página atual é um dos seus itens (o menu fica montado entre as páginas).
const abertos = reactive<Record<string, boolean>>(Object.fromEntries(grupos.map((grupo) => [grupo.id, grupoAtivo(grupo)])));
watch(
    () => page.url,
    () => grupos.filter(grupoAtivo).forEach((grupo) => (abertos[grupo.id] = true)),
);

const classeLink =
    'flex min-h-11 items-center gap-3 rounded-[12px] px-4 font-semibold text-home-tinta hover:bg-nevoa aria-[current=page]:bg-acao aria-[current=page]:text-sobre-acao';
</script>

<template>
    <nav aria-label="Retaguarda">
        <ul class="flex flex-col gap-1">
            <li v-for="grupo in grupos" :key="grupo.id">
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 rounded-[12px] px-4 text-left font-semibold text-home-tinta hover:bg-nevoa"
                    :aria-expanded="abertos[grupo.id] ? 'true' : 'false'"
                    :aria-controls="grupo.id"
                    @click="abertos[grupo.id] = !abertos[grupo.id]"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="grupo.icone" />
                    </svg>
                    <span class="flex-1">{{ grupo.texto }}</span>
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        class="size-4 shrink-0 transition-transform motion-reduce:transition-none"
                        :class="abertos[grupo.id] ? 'rotate-180' : ''"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <ul v-show="abertos[grupo.id]" :id="grupo.id" class="mt-1 flex flex-col gap-1 pl-4">
                    <li v-for="item in grupo.itens" :key="item.href">
                        <Link :href="item.href" :class="classeLink" :aria-current="atual(item.href) ? 'page' : undefined">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="item.icone" />
                            </svg>
                            {{ item.texto }}
                        </Link>
                    </li>
                </ul>
            </li>

            <li>
                <Link :href="usuarios.href" :class="classeLink" :aria-current="atual(usuarios.href) ? 'page' : undefined">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="usuarios.icone" />
                    </svg>
                    {{ usuarios.texto }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
