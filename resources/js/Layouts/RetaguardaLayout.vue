<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FlashMessage from '../Components/FlashMessage.vue';
import DicaRetaguarda from '../Components/Retaguarda/DicaRetaguarda.vue';
import MenuRetaguarda from '../Components/Retaguarda/MenuRetaguarda.vue';
import MenuUsuario from '../Components/Retaguarda/MenuUsuario.vue';
import SkipLink from '../Components/SkipLink.vue';
import TestEnvBanner from '../Components/TestEnvBanner.vue';
import VidroLiquido from '../Components/VidroLiquido.vue';

const page = usePage();
const ambienteTeste = computed(() => page.props.ambienteTeste);

// Do computador para cima (1024px) o menu fica fixo à esquerda e pode ser recolhido aos ícones; a escolha fica
// guardada neste navegador. Abaixo disso ele é uma gaveta, aberta pelo botão "Menu", que fecha ao trocar de página,
// com Esc e com clique fora.
const CHAVE_RECOLHIDO = 'retaguardaMenuRecolhido';

const lerRecolhido = (): boolean => {
    try {
        return localStorage.getItem(CHAVE_RECOLHIDO) === '1';
    } catch {
        return false;
    }
};

const recolhido = ref<boolean>(lerRecolhido());

const alternarRecolhido = (): void => {
    recolhido.value = !recolhido.value;
    try {
        localStorage.setItem(CHAVE_RECOLHIDO, recolhido.value ? '1' : '0');
    } catch {
        // Armazenamento indisponível: vale só nesta visita.
    }
};

const menuAberto = ref<boolean>(false);
const botaoMenu = ref<HTMLButtonElement | null>(null);
const botaoFechar = ref<HTMLButtonElement | null>(null);

const abrirMenu = async (): Promise<void> => {
    menuAberto.value = true;
    await nextTick();
    botaoFechar.value?.focus();
};

const fecharMenu = (): void => {
    if (!menuAberto.value) return;
    menuAberto.value = false;
    botaoMenu.value?.focus();
};

const pararDeOuvir = router.on('navigate', () => {
    menuAberto.value = false;
});

onBeforeUnmount(pararDeOuvir);
</script>

<template>
    <!-- Retaguarda: identidade do portal do proponente (Montserrat, Barlow, azul-marinho e vidro) num painel de
         sistema: menu lateral azul-marinho e, em cada página, a faixa de título (PaginaRetaguarda). -->
    <div class="flex min-h-screen flex-col">
        <SkipLink />
        <TestEnvBanner v-if="ambienteTeste" />
        <VidroLiquido class="flex flex-1 flex-col bg-parchment text-home-tinta lg:flex-row">
            <!-- Celular e tablet: barra fina com o botão do menu e a logo. -->
            <header class="barra-institucional sticky top-0 z-30 flex items-center gap-3 border-b border-white/10 bg-noite px-3 py-2 text-white lg:hidden [html.alto-contraste_&]:bg-fundo">
                <button
                    ref="botaoMenu"
                    type="button"
                    class="pressionavel inline-flex min-h-11 min-w-11 items-center justify-center rounded-pill hover:bg-white/15"
                    :aria-expanded="menuAberto ? 'true' : 'false'"
                    aria-controls="menu-retaguarda"
                    @click="abrirMenu"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M4 7h16M4 12h16M4 17h10" />
                    </svg>
                    <span class="sr-only">Menu</span>
                </button>
                <Link href="/retaguarda" class="flex min-w-0 items-center">
                    <img
                        src="/images/logo-fgm-prefeitura-branco.png"
                        alt="FGM – Fundação Gregório de Mattos e Prefeitura de Salvador"
                        width="1395"
                        height="210"
                        class="h-6 w-auto max-w-[55vw] object-contain object-left"
                    />
                </Link>
            </header>

            <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" leave-to-class="opacity-0">
                <div v-if="menuAberto" aria-hidden="true" class="fixed inset-0 z-40 bg-noite/60 backdrop-blur-sm lg:hidden" @click="fecharMenu" />
            </Transition>

            <!-- A gaveta desliza ao abrir e ao fechar (visibility entra na transição, para o invisible só valer no fim).
                 Recolher no computador é instantâneo: animar a largura refaria o layout da página a cada quadro. -->
            <aside
                id="menu-retaguarda"
                class="menu-retaguarda barra-institucional fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col bg-noite text-white transition-[transform,visibility] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none lg:sticky lg:top-0 lg:h-screen lg:max-w-none lg:translate-x-0 [html.alto-contraste_&]:bg-fundo"
                :class="[menuAberto ? 'translate-x-0' : 'invisible -translate-x-full lg:visible', recolhido ? 'lg:w-20' : 'lg:w-72']"
                @keydown.esc="fecharMenu"
            >
                <!-- Brilho azul no alto, como na faixa das páginas. -->
                <div aria-hidden="true" class="home-veu pointer-events-none absolute inset-0 overflow-hidden">
                    <div class="absolute inset-0 bg-[radial-gradient(120%_40%_at_0%_0%,rgba(0,92,169,0.55)_0%,rgba(0,92,169,0)_70%)]"></div>
                </div>

                <div class="relative flex min-h-16 items-center gap-2 border-b border-white/10 px-4" :class="recolhido ? 'lg:justify-center lg:px-2' : ''">
                    <Link href="/retaguarda" class="flex min-w-0 flex-1 items-center justify-center py-3" :class="recolhido ? 'lg:hidden' : ''">
                        <img
                            src="/images/logo-fgm-prefeitura-branco.png"
                            alt="FGM – Fundação Gregório de Mattos e Prefeitura de Salvador"
                            width="1395"
                            height="210"
                            class="h-7 w-auto max-w-full object-contain object-center"
                        />
                    </Link>

                    <!-- Recolhido: o botão de abrir ocupa o lugar da logo. -->
                    <button
                        type="button"
                        class="pressionavel hidden size-11 shrink-0 items-center justify-center rounded-[12px] text-white/70 hover:bg-white/10 hover:text-white lg:inline-flex"
                        :data-dica="recolhido ? 'Expandir menu' : 'Recolher menu'"
                        data-dica-lado="direita"
                        @click="alternarRecolhido"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="16" rx="3" />
                            <path d="M9 4v16" />
                            <path :d="recolhido ? 'm13 10 2 2-2 2' : 'm16 10-2 2 2 2'" />
                        </svg>
                        <span class="sr-only">{{ recolhido ? 'Expandir menu' : 'Recolher menu' }}</span>
                    </button>

                    <button
                        ref="botaoFechar"
                        type="button"
                        class="pressionavel inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-white/15 lg:hidden"
                        @click="fecharMenu"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                        <span class="sr-only">Fechar menu</span>
                    </button>
                </div>

                <div class="relative flex-1 overflow-y-auto px-3 py-6 [scrollbar-color:rgba(255,255,255,0.2)_transparent] [scrollbar-width:thin]">
                    <!-- Na gaveta do celular o menu aparece sempre inteiro. -->
                    <MenuRetaguarda :recolhido="recolhido && !menuAberto" />
                </div>

                <div class="relative border-t border-white/10 p-3">
                    <MenuUsuario :recolhido="recolhido && !menuAberto" />
                </div>
            </aside>

            <main id="conteudo" tabindex="-1" class="min-w-0 flex-1 focus:outline-none">
                <FlashMessage />
                <slot />
            </main>

            <!-- Dica dos botões só com ícone (data-dica), para a retaguarda inteira. -->
            <DicaRetaguarda />
        </VidroLiquido>
    </div>
</template>
