<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import AccessibilityControls from '../AccessibilityControls.vue';

// Botão de vidro com o painel de acessibilidade (tamanho do texto, alto contraste e modo noturno), como o do portal
// do proponente. Fecha com Esc, com o ×, com clique fora e quando o foco sai dele pelo teclado.
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

watch(aberto, (estaAberto) => {
    if (estaAberto) document.addEventListener('pointerdown', fecharAoClicarFora);
    else document.removeEventListener('pointerdown', fecharAoClicarFora);
});

onBeforeUnmount(() => document.removeEventListener('pointerdown', fecharAoClicarFora));
</script>

<template>
    <div class="relative" @keydown.esc="fecharEVoltarAoBotao">
        <button
            ref="botao"
            type="button"
            class="vidro vidro-pilula pressionavel group inline-flex min-h-11 items-center gap-2 rounded-pill px-3 text-[0.8125rem] font-semibold text-white aria-expanded:ring-2 aria-expanded:ring-white/60"
            :aria-expanded="aberto ? 'true' : 'false'"
            aria-controls="painel-acessibilidade"
            @click="aberto = !aberto"
            @focusout="fecharAoSairDoFoco"
        >
            <!-- Figura do acesso universal: pessoa de braços abertos. -->
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="4.5" r="2.2" fill="currentColor" stroke="none" />
                <path d="M4.5 8.6c2.5.9 5 1.3 7.5 1.3s5-.4 7.5-1.3M12 9.9v4.6m0 0-3.2 6.5m3.2-6.5 3.2 6.5" />
            </svg>
            <span class="sr-only sm:not-sr-only">Acessibilidade</span>
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                class="size-4 transition-transform group-aria-expanded:rotate-180 motion-reduce:transition-none"
                fill="none"
                stroke="currentColor"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
            enter-from-class="-translate-y-1 scale-[0.98] opacity-0"
            leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
            leave-to-class="-translate-y-1 scale-[0.98] opacity-0"
        >
            <!-- Sem o anel branco da faixa: o painel é claro. -->
            <div
                v-show="aberto"
                id="painel-acessibilidade"
                ref="painel"
                class="absolute top-full right-0 z-50 mt-3 w-[min(20rem,calc(100vw-2rem))] origin-top-right rounded-[20px] border border-hairline bg-superficie p-5 text-home-tinta shadow-[0_24px_60px_-24px_rgba(6,36,74,0.5)] [&_:focus-visible]:outline-acao"
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
