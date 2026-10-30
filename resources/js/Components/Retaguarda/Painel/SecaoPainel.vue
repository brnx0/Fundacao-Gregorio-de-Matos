<script setup lang="ts">
defineProps<{
    /** Id da seção (âncora e rótulo do título). */
    id: string;
    titulo: string;
    descricao?: string;
    /** Caminho (atributo d) do ícone de 24px. */
    icone: string;
    /** Novos números a caminho depois de trocar o filtro: os atuais ficam esmaecidos até chegarem. */
    atualizando?: boolean;
}>();
</script>

<template>
    <!-- Seção do Painel: cabeçalho com ícone, título e o que os números consideram; os quadros vêm no corpo. -->
    <section :id="id" :aria-labelledby="`${id}-titulo`" :aria-busy="atualizando ? 'true' : undefined" class="painel-retaguarda scroll-mt-6 overflow-hidden">
        <header class="flex flex-col gap-3 border-b border-hairline bg-parchment px-5 py-4 @3xl:flex-row @3xl:items-center @3xl:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span aria-hidden="true" class="inline-flex size-10 shrink-0 items-center justify-center rounded-[12px] bg-nevoa text-acao">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icone" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <h2 :id="`${id}-titulo`" class="font-barlow text-[1.375rem] leading-none font-extrabold tracking-[0.01em] uppercase">{{ titulo }}</h2>
                    <p v-if="descricao" class="mt-1 text-[0.8125rem] text-tinta-suave">{{ descricao }}</p>
                </div>
            </div>
            <slot name="extra" />
        </header>
        <div class="grid gap-4 p-4 transition-opacity duration-300 motion-reduce:transition-none @xl:p-5" :class="atualizando ? 'opacity-55' : ''">
            <slot />
        </div>
    </section>
</template>
