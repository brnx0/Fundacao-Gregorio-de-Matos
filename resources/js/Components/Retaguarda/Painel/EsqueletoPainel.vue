<script setup lang="ts">
withDefaults(
    defineProps<{
        /** Nome da seção, para o anúncio do leitor de tela. */
        secao: string;
        /** Forma do que vem: cartões de números, quadros de gráfico ou um quadro alto (mapa). */
        forma?: 'cartoes' | 'graficos' | 'mapa';
    }>(),
    { forma: 'graficos' },
);
</script>

<template>
    <!-- Lugar da seção enquanto os números chegam (deferred): blocos pulsando no formato do que vem. -->
    <div role="status" class="painel-retaguarda overflow-hidden">
        <span class="sr-only">Carregando {{ secao }}…</span>
        <div aria-hidden="true" class="flex items-center gap-3 border-b border-hairline bg-parchment px-5 py-4">
            <span class="size-10 rounded-[12px] bg-nevoa motion-safe:animate-pulse" />
            <span class="flex flex-col gap-2">
                <span class="h-4 w-40 rounded-pill bg-nevoa motion-safe:animate-pulse" />
                <span class="h-3 w-64 max-w-full rounded-pill bg-nevoa motion-safe:animate-pulse" />
            </span>
        </div>
        <div aria-hidden="true" class="grid gap-4 p-4 @xl:p-5">
            <div v-if="forma === 'cartoes'" class="grid gap-4 @xl:grid-cols-2 @5xl:grid-cols-4">
                <span v-for="indice in 8" :key="indice" class="h-36 rounded-[16px] bg-nevoa motion-safe:animate-pulse" />
            </div>
            <span v-else-if="forma === 'mapa'" class="h-96 rounded-[16px] bg-nevoa motion-safe:animate-pulse" />
            <div v-else class="grid gap-4 @4xl:grid-cols-3">
                <span v-for="indice in 3" :key="indice" class="h-64 rounded-[16px] bg-nevoa motion-safe:animate-pulse" />
            </div>
        </div>
    </div>
</template>
