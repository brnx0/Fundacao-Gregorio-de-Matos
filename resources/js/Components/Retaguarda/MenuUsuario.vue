<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

defineProps<{
    /** Menu lateral recolhido: o botão mostra só o avatar. */
    recolhido?: boolean;
}>();

// Caminhos das rotas retaguarda.perfil.edit, retaguarda.senha.edit e retaguarda.sair (o front não tem helper de rotas nomeadas).
const URL_PERFIL = '/retaguarda/perfil';
const URL_SENHA = '/retaguarda/minha-senha';
const URL_SAIR = '/retaguarda/sair';

const page = usePage();
const usuario = computed(() => page.props.auth?.user);

// "Maria Gestora Teste" → "MT", para o avatar.
const iniciais = computed(() => {
    const nomes = usuario.value?.nome.trim().split(/\s+/) ?? [];
    return ((nomes[0]?.[0] ?? '') + (nomes.length > 1 ? (nomes.at(-1)?.[0] ?? '') : '')).toUpperCase();
});

// Token do <meta> de app.blade.php, como no PortalProponenteLayout.
const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const aberto = ref<boolean>(false);
const raiz = ref<HTMLElement | null>(null);
const botao = ref<HTMLButtonElement | null>(null);
const lista = ref<HTMLElement | null>(null);
const formularioSair = ref<HTMLFormElement | null>(null);

const itens = (): HTMLElement[] => Array.from(lista.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

const focarItem = (indice: number): void => {
    const todos = itens();
    if (todos.length === 0) return;
    todos[(indice + todos.length) % todos.length]?.focus();
};

// Padrão "menu button" (WAI-ARIA): abre focando o primeiro item (ou o último, pela seta para cima).
const abrir = async (primeiro = true): Promise<void> => {
    aberto.value = true;
    await nextTick();
    focarItem(primeiro ? 0 : -1);
};

const fechar = (voltarAoBotao = true): void => {
    if (!aberto.value) return;
    aberto.value = false;
    if (voltarAoBotao) botao.value?.focus();
};

const alternar = (): void => {
    if (aberto.value) {
        fechar();
    } else {
        void abrir();
    }
};

const teclaNoBotao = (evento: KeyboardEvent): void => {
    if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
        evento.preventDefault();
        void abrir(evento.key === 'ArrowDown');
    }
};

const teclaNaLista = (evento: KeyboardEvent): void => {
    const todos = itens();
    const atual = todos.indexOf(document.activeElement as HTMLElement);

    switch (evento.key) {
        case 'ArrowDown':
            evento.preventDefault();
            focarItem(atual + 1);
            break;
        case 'ArrowUp':
            evento.preventDefault();
            focarItem(atual - 1);
            break;
        case 'Home':
            evento.preventDefault();
            focarItem(0);
            break;
        case 'End':
            evento.preventDefault();
            focarItem(-1);
            break;
        case 'Escape':
            evento.preventDefault();
            fechar();
            break;
        case 'Tab':
            fechar(false);
            break;
    }
};

const sair = (): void => {
    formularioSair.value?.submit();
};

const fecharAoClicarFora = (evento: PointerEvent): void => {
    if (aberto.value && !raiz.value?.contains(evento.target as Node)) fechar(false);
};

const pararDeOuvir = router.on('navigate', () => {
    aberto.value = false;
});

onMounted(() => document.addEventListener('pointerdown', fecharAoClicarFora));
onBeforeUnmount(() => {
    pararDeOuvir();
    document.removeEventListener('pointerdown', fecharAoClicarFora);
});

const classeItem =
    'flex min-h-11 w-full items-center gap-3 rounded-[10px] px-3 text-left text-[0.9375rem] font-semibold no-underline outline-none hover:bg-nevoa focus-visible:bg-nevoa focus-visible:ring-2 focus-visible:ring-acao aria-[current=page]:text-acao';
</script>

<template>
    <div v-if="usuario" ref="raiz" class="relative">
        <!-- No pé do menu lateral (azul-marinho): avatar, nome e CPF; a lista abre para cima. -->
        <button
            ref="botao"
            type="button"
            class="pressionavel group flex min-h-14 w-full items-center gap-3 rounded-[14px] p-2 text-left text-white hover:bg-white/10 focus-visible:outline-white aria-expanded:bg-white/10"
            :class="recolhido ? 'justify-center' : ''"
            aria-haspopup="menu"
            :aria-expanded="aberto ? 'true' : 'false'"
            aria-controls="menu-usuario"
            :data-dica="recolhido ? usuario.nome : undefined"
            data-dica-lado="direita"
            @click="alternar"
            @keydown="teclaNoBotao"
        >
            <span aria-hidden="true" class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-[0.8125rem] font-bold text-home-azul">
                {{ iniciais }}
            </span>
            <span class="min-w-0 flex-1" :class="recolhido ? 'sr-only' : ''">
                <span class="block truncate text-[0.875rem] font-semibold">{{ usuario.nome }}</span>
                <span class="block text-[0.75rem] text-white/60">Meu acesso<span class="sr-only">, menu do usuário</span></span>
            </span>
            <svg
                v-if="!recolhido"
                aria-hidden="true"
                viewBox="0 0 24 24"
                class="size-4 shrink-0 text-white/70 transition-transform group-aria-expanded:rotate-180 motion-reduce:transition-none"
                fill="none"
                stroke="currentColor"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="m18 15-6-6-6 6" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
            enter-from-class="translate-y-1 scale-[0.98] opacity-0"
            leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
            leave-to-class="translate-y-1 scale-[0.98] opacity-0"
        >
            <div
                v-show="aberto"
                id="menu-usuario"
                ref="lista"
                role="menu"
                aria-label="Menu do usuário"
                class="absolute bottom-full left-0 z-40 mb-2 w-64 max-w-[calc(100vw-2rem)] origin-bottom-left rounded-[16px] border border-hairline bg-superficie p-1.5 text-home-tinta shadow-[0_24px_60px_-24px_rgba(6,36,74,0.55)]"
                @keydown="teclaNaLista"
            >
                <div class="px-3 pt-2 pb-2.5" role="none">
                    <p class="truncate text-[0.9375rem] font-bold">{{ usuario.nome }}</p>
                    <p class="text-[0.8125rem] text-tinta-suave">CPF <span class="tabular-nums">{{ usuario.cpfMascarado }}</span></p>
                </div>
                <div class="my-1 border-t border-hairline" role="separator" />

                <Link :href="URL_PERFIL" role="menuitem" tabindex="-1" :class="classeItem" :aria-current="page.url === URL_PERFIL ? 'page' : undefined">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21a8 8 0 0 1 16 0" />
                    </svg>
                    Meu perfil
                </Link>
                <Link :href="URL_SENHA" role="menuitem" tabindex="-1" :class="classeItem" :aria-current="page.url === URL_SENHA ? 'page' : undefined">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15.5 7.5a4 4 0 1 1-3.4 6.1L4 21.5V19h2.5v-2H9v-2.1l1.9-1.9A4 4 0 0 1 15.5 7.5z" />
                    </svg>
                    Trocar senha
                </Link>

                <div class="my-1 border-t border-hairline" role="separator" />

                <button type="button" role="menuitem" tabindex="-1" :class="classeItem" class="text-erro-texto" @click="sair">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4" />
                    </svg>
                    Sair
                </button>
            </div>
        </Transition>

        <!-- Sair é um POST comum (não Inertia): a sessão termina e o navegador segue o redirecionamento. -->
        <form ref="formularioSair" method="post" :action="URL_SAIR" class="hidden">
            <input type="hidden" name="_token" :value="csrf" />
        </form>
    </div>
</template>
