<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AccessibilityControls from './AccessibilityControls.vue';

// Botão de acessibilidade dos cabeçalhos azul-marinho (portal do proponente e área do avaliador) e o painel dele: fecha
// com Esc, com o ×, com clique fora, quando o foco sai dele pelo teclado e ao trocar de página. O painel se alinha à
// direita do primeiro ancestral posicionado (a linha de controles do cabeçalho), por isso a raiz não tem posição.
const aberto = ref<boolean>(false);
const botao = ref<HTMLButtonElement | null>(null);
const painel = ref<HTMLElement | null>(null);

const dentro = (alvo: Node | null): boolean => alvo !== null && (!!botao.value?.contains(alvo) || !!painel.value?.contains(alvo));

const fecharAoClicarFora = (evento: PointerEvent): void => {
    if (!dentro(evento.target as Node)) aberto.value = false;
};

// Sem relatedTarget (clique numa área sem foco) quem decide é o clique fora.
const fecharAoSairDoFoco = (evento: FocusEvent): void => {
    const destino = evento.relatedTarget as Node | null;
    if (destino && !dentro(destino)) aberto.value = false;
};

const fecharEVoltarAoBotao = (): void => {
    if (!aberto.value) return;
    aberto.value = false;
    botao.value?.focus();
};

watch(aberto, (valor) => {
    if (valor) document.addEventListener('pointerdown', fecharAoClicarFora);
    else document.removeEventListener('pointerdown', fecharAoClicarFora);
});

const pararDeOuvir = router.on('navigate', () => {
    aberto.value = false;
});

onBeforeUnmount(() => {
    pararDeOuvir();
    document.removeEventListener('pointerdown', fecharAoClicarFora);
});
</script>

<template>
    <div class="contents" @keydown.esc="fecharEVoltarAoBotao">
        <!-- Ícone e seta, sem o texto (fica para o leitor de tela). No celular, só o ícone: com a seta o botão espreme a
             logo. -->
        <button
            ref="botao"
            type="button"
            class="pressionavel group inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1 rounded-pill border border-white/15 bg-white/10 px-2.5 text-white sm:pr-3 hover:bg-white/20 focus-visible:outline-white aria-expanded:bg-white/25 [html.alto-contraste_&]:border-hairline aria-expanded:ring-2 aria-expanded:ring-white/60"
            :aria-expanded="aberto ? 'true' : 'false'"
            aria-controls="painel-acessibilidade"
            title="Acessibilidade"
            @click="aberto = !aberto"
            @focusout="fecharAoSairDoFoco"
        >
            <!-- Figura do acesso universal: pessoa de braços abertos. -->
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="4.5" r="2.2" fill="currentColor" stroke="none" />
                <path d="M4.5 8.6c2.5.9 5 1.3 7.5 1.3s5-.4 7.5-1.3M12 9.9v4.6m0 0-3.2 6.5m3.2-6.5 3.2 6.5" />
            </svg>
            <span class="sr-only">Acessibilidade</span>
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                class="hidden size-4 transition-transform group-aria-expanded:rotate-180 motion-reduce:transition-none sm:block"
                fill="none"
                stroke="currentColor"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <!-- Logo depois do botão: pelo teclado, o Tab entra no painel antes de chegar ao "Sair". -->
        <Transition
            enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
            enter-from-class="-translate-y-1 scale-[0.98] opacity-0"
            leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
            leave-to-class="-translate-y-1 scale-[0.98] opacity-0"
        >
            <div
                v-show="aberto"
                id="painel-acessibilidade"
                ref="painel"
                class="absolute top-full right-0 z-50 mt-3 w-[min(20rem,calc(100vw-2rem))] origin-top-right rounded-[20px] border border-hairline bg-superficie p-5 text-home-tinta shadow-[0_24px_60px_-24px_rgba(6,36,74,0.5)]"
                @focusout="fecharAoSairDoFoco"
            >
                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="font-bold">Acessibilidade</p>
                    <button
                        type="button"
                        class="pressionavel -mr-2.5 inline-flex size-11 items-center justify-center rounded-full text-tinta-suave hover:bg-parchment hover:text-home-tinta"
                        aria-label="Fechar acessibilidade"
                        @click="fecharEVoltarAoBotao"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>
                <AccessibilityControls variante="painel" />
            </div>
        </Transition>
    </div>
</template>
