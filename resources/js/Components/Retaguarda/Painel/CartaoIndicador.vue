<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import MiniLinha from './MiniLinha.vue';

defineProps<{
    rotulo: string;
    /** Valor já formatado. */
    valor: string;
    /** Caminho (atributo d) do ícone de 24px. */
    icone: string;
    /** Série mensal para o minigráfico, com a frase que a descreve para o leitor de tela. */
    serie?: number[];
    descricaoDaSerie?: string;
    /** Linha de baixo: o que mudou no mês ou o que o número considera. */
    rodape?: string;
    /** Tela da retaguarda com a lista por trás do número. */
    href?: string;
}>();
</script>

<template>
    <!-- Cartão de número: rótulo com ícone, o número grande, o minigráfico dos meses e o rodapé. -->
    <article class="flex min-w-0 flex-col gap-3 rounded-[16px] border border-hairline bg-superficie p-4">
        <div class="flex items-center gap-2.5">
            <span aria-hidden="true" class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-nevoa text-acao">
                <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="icone" />
                </svg>
            </span>
            <h3 class="min-w-0 flex-1 text-[0.8125rem] leading-snug font-semibold text-tinta-suave">{{ rotulo }}</h3>
            <Link v-if="href" :href="href" class="acao-icone size-8! shrink-0" :data-dica="`Abrir ${rotulo.toLowerCase()}`">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M13 6l6 6-6 6" />
                </svg>
                <span class="sr-only">Abrir {{ rotulo.toLowerCase() }}</span>
            </Link>
        </div>
        <p class="text-[2rem] leading-none font-bold tracking-[-0.02em] text-home-tinta">{{ valor }}</p>
        <MiniLinha v-if="serie" :valores="serie" :descricao="descricaoDaSerie ?? ''" />
        <p v-if="rodape" class="mt-auto border-t border-hairline pt-2.5 text-[0.75rem] text-tinta-suave">{{ rodape }}</p>
    </article>
</template>
