<script setup lang="ts">
import { reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ehItemAtual, gruposMenu, itemPainel, type GrupoMenu } from './menuRetaguarda';

defineProps<{
    /** Menu recolhido (só os ícones): todos os itens à mostra, e os nomes ficam para o leitor de tela e na dica (DicaRetaguarda). */
    recolhido?: boolean;
}>();

const page = usePage();

// Cada grupo abre e fecha pela seta. Ao carregar a retaguarda, todos começam fechados; o menu fica montado entre as
// páginas (layout persistente), então o que foi aberto continua aberto ao navegar.
const abertos = reactive<Record<string, boolean>>({});

const alternar = (grupo: GrupoMenu): void => {
    abertos[grupo.id] = !abertos[grupo.id];
};

/** O grupo tem a página atual: o cabeçalho fica em destaque, para achar onde se está com o grupo fechado. */
const temPaginaAtual = (grupo: GrupoMenu): boolean => grupo.itens.some((item) => ehItemAtual(page.url, item.href));
</script>

<template>
    <!-- Menu lateral sobre o azul-marinho: o Painel no topo e, abaixo, cada grupo é um cabeçalho com seta que abre e
         fecha os seus itens. -->
    <nav aria-label="Retaguarda">
        <ul class="flex flex-col gap-1">
            <li class="p-1">
                <Link
                    :href="itemPainel.href"
                    class="item-menu-retaguarda"
                    :class="recolhido ? 'justify-center px-0' : ''"
                    :data-dica="recolhido ? itemPainel.texto : undefined"
                    data-dica-lado="direita"
                    :aria-current="ehItemAtual(page.url, itemPainel.href) ? 'page' : undefined"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="itemPainel.icone" />
                    </svg>
                    <span :class="recolhido ? 'sr-only' : 'truncate'">{{ itemPainel.texto }}</span>
                </Link>
            </li>
            <li v-for="grupo in gruposMenu" :key="grupo.id">
                <!-- Recolhido não há cabeçalho: um traço separa os grupos, e os itens ficam à mostra. -->
                <span v-if="recolhido" aria-hidden="true" class="mx-auto my-2 block h-px w-8 bg-white/15" />
                <button
                    v-else
                    :id="`${grupo.id}-botao`"
                    type="button"
                    class="group flex min-h-11 w-full items-center gap-3 rounded-[12px] px-3 text-left text-[0.75rem] font-bold tracking-[0.14em] uppercase transition-colors hover:bg-white/10 hover:text-white motion-reduce:transition-none"
                    :class="temPaginaAtual(grupo) ? 'text-white' : 'text-white/60'"
                    :aria-expanded="abertos[grupo.id] ? 'true' : 'false'"
                    :aria-controls="grupo.id"
                    @click="alternar(grupo)"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-[1.125rem] shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="grupo.icone" />
                    </svg>
                    <span class="flex-1 truncate">{{ grupo.texto }}</span>
                    <span v-if="temPaginaAtual(grupo)" class="sr-only">, página atual neste grupo</span>
                    <span v-if="temPaginaAtual(grupo) && !abertos[grupo.id]" aria-hidden="true" class="size-1.5 shrink-0 rounded-full bg-white" />
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        class="size-4 shrink-0 transition-transform duration-200 group-aria-expanded:rotate-180 motion-reduce:transition-none"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <!-- Abre e fecha deslizando (a grade vai de 0fr a 1fr). Fechado, os itens saem do Tab e do leitor de tela (inert). -->
                <div
                    class="grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
                    :class="recolhido || abertos[grupo.id] ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                    :inert="(!recolhido && !abertos[grupo.id]) || undefined"
                >
                    <!-- O corte fica num invólucro sem respiro (fechado, some por inteiro); o respiro da lista (p-1) deixa o
                         anel do foco aparecer dentro do corte. -->
                    <div class="min-h-0 overflow-hidden">
                        <ul
                            :id="grupo.id"
                            :aria-labelledby="recolhido ? undefined : `${grupo.id}-botao`"
                            :aria-label="recolhido ? grupo.texto : undefined"
                            class="flex flex-col gap-1 p-1"
                            :class="recolhido ? '' : 'ml-4 border-l border-white/10 pl-2'"
                        >
                            <li v-for="item in grupo.itens" :key="item.href">
                                <Link
                                    :href="item.href"
                                    class="item-menu-retaguarda"
                                    :class="recolhido ? 'justify-center px-0' : ''"
                                    :data-dica="recolhido ? item.texto : undefined"
                                    data-dica-lado="direita"
                                    :aria-current="ehItemAtual(page.url, item.href) ? 'page' : undefined"
                                >
                                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path :d="item.icone" />
                                    </svg>
                                    <span :class="recolhido ? 'sr-only' : 'truncate'">{{ item.texto }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </li>
        </ul>
    </nav>
</template>
