<script setup lang="ts">
import { fotoVitrine } from './fotosVitrine';
import type { EditalPortal, SituacaoEdital } from '../types';

defineProps<{
    edital: Pick<EditalPortal, 'slug' | 'titulo' | 'resumo' | 'categoria' | 'periodo' | 'situacao' | 'situacaoRotulo' | 'imagem'>;
    /** Posição na lista: escolhe a foto da vitrine do edital sem imagem própria. */
    indice: number;
}>();

const corSituacao: Record<SituacaoEdital, string> = {
    aberto: 'bg-situacao-aberto',
    em_breve: 'bg-situacao-em-breve',
    encerrado: 'bg-situacao-encerrado',
};
</script>

<template>
    <!-- Card da tela Editais do portal; a prévia da retaguarda (PreviaCardEdital) usa o mesmo. -->
    <article
        :aria-labelledby="`edital-${edital.slug}`"
        class="group flex h-full flex-col overflow-hidden rounded-[24px] border border-hairline bg-superficie shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)]"
    >
        <!-- Sem foto própria, o card usa uma foto da vitrine; as duas são decorativas (o título está no texto). -->
        <div class="home-escuro relative aspect-[4/3] overflow-hidden bg-noite text-white">
            <img
                :src="edital.imagem ?? fotoVitrine(indice).src"
                alt=""
                class="home-foto absolute inset-0 size-full object-cover transition-transform duration-700 ease-out motion-safe:group-hover:scale-105"
                :style="edital.imagem ? undefined : { objectPosition: fotoVitrine(indice).enquadramento }"
                loading="lazy"
                decoding="async"
            />
            <div
                aria-hidden="true"
                class="home-veu absolute inset-0 bg-[linear-gradient(0deg,rgba(4,26,56,0.9)_0%,rgba(4,26,56,0.4)_40%,rgba(4,26,56,0)_68%)]"
            ></div>
            <span class="vidro vidro-escuro vidro-pilula absolute top-4 left-4 inline-flex items-center gap-2 rounded-pill px-3 py-1 text-[0.8125rem] font-semibold">
                <span aria-hidden="true" class="size-2 rounded-full" :class="corSituacao[edital.situacao]"></span>
                {{ edital.situacaoRotulo }}
            </span>
            <p class="absolute inset-x-5 bottom-4 text-[0.75rem] font-bold tracking-[0.2em] uppercase">{{ edital.categoria }}</p>
        </div>

        <div class="flex flex-1 flex-col gap-4 p-5 md:p-6">
            <h2 :id="`edital-${edital.slug}`" class="font-barlow text-[1.6rem] leading-[1] font-extrabold tracking-[0.01em] break-words text-home-tinta uppercase">
                {{ edital.titulo }}
            </h2>
            <!-- A descrição inteira abre a página do edital; no card, até três linhas. -->
            <p v-if="edital.resumo" class="line-clamp-3 text-[0.9375rem] leading-[1.55] text-tinta-suave">{{ edital.resumo }}</p>
            <dl class="flex flex-col gap-1">
                <dt class="text-[0.75rem] font-semibold tracking-[0.16em] text-tinta-suave uppercase">Inscrições</dt>
                <dd class="font-medium tabular-nums">{{ edital.periodo }}</dd>
            </dl>
            <slot />
            <div class="mt-auto flex flex-wrap items-center gap-2 pt-1">
                <slot name="acoes" />
            </div>
        </div>
    </article>
</template>
