<script setup lang="ts">
defineProps<{
    titulo: string;
    /** O que o número considera (base, período, unidade). */
    descricao?: string;
}>();
</script>

<template>
    <!-- Quadro de um gráfico: título e base em cima; o gráfico (com a sua tabela para leitor de tela) no corpo. É um
         @container: o gráfico se arruma pela largura do quadro (lado a lado ou empilhado), não pela da página. -->
    <figure class="@container flex min-w-0 flex-col gap-4 rounded-[16px] border border-hairline bg-superficie p-4 @xl:p-5">
        <figcaption class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-[0.9375rem] leading-snug font-bold text-home-tinta">{{ titulo }}</h3>
                <p v-if="descricao" class="mt-0.5 text-[0.75rem] leading-snug text-tinta-suave">{{ descricao }}</p>
            </div>
            <slot name="extra" />
        </figcaption>
        <slot />
    </figure>
</template>
