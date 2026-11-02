<script setup lang="ts">
import type { EditalResumo, SituacaoEdital } from '../types';

defineProps<{
    edital: Pick<EditalResumo, 'slug' | 'titulo' | 'resumo' | 'categoria' | 'situacao' | 'situacaoRotulo' | 'inscricoesInicio' | 'inscricoesFim' | 'pdf'>;
    foto: { src: string; enquadramento: string };
}>();

// Sem rótulo próprio, vale o situacaoRotulo do backend.
const situacoes: Record<SituacaoEdital, { cor: string; rotulo?: string }> = {
    aberto: { cor: 'bg-situacao-aberto', rotulo: 'Inscrições abertas' },
    em_breve: { cor: 'bg-situacao-em-breve' },
    encerrado: { cor: 'bg-situacao-encerrado' },
};
</script>

<template>
    <!-- Miolo do card da vitrine (EditaisCarrossel; também na prévia da retaguarda). "contents": as partes ficam na
         grade do <article> do slide, que dá a posição, o tamanho e a animação. -->
    <div class="contents">
        <img
            :src="foto.src"
            alt=""
            class="slide-foto home-foto absolute inset-0 size-full object-cover"
            :style="{ objectPosition: foto.enquadramento }"
            loading="lazy"
            decoding="async"
        />
        <div
            aria-hidden="true"
            class="home-veu absolute inset-0 bg-[linear-gradient(0deg,rgba(4,26,56,0.94)_0%,rgba(4,26,56,0.62)_42%,rgba(4,26,56,0)_72%)]"
        ></div>

        <!-- O espaçador dá a proporção 20/27 do anexo; se o texto for mais alto (título longo, A+), o card cresce. -->
        <div aria-hidden="true" class="col-start-1 row-start-1 aspect-[20/27]"></div>
        <div class="slide-texto relative col-start-1 row-start-1 flex flex-col justify-end gap-2.5 p-[22px] md:gap-4 md:p-10">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                <span class="text-[0.8125rem] font-bold tracking-[0.2em] uppercase">{{ edital.categoria }}</span>
                <span class="vidro vidro-pilula inline-flex items-center gap-2 rounded-pill px-3.5 py-1.5 text-[0.8125rem] font-semibold">
                    <span aria-hidden="true" class="size-2 rounded-full" :class="situacoes[edital.situacao].cor"></span>
                    {{ situacoes[edital.situacao].rotulo ?? edital.situacaoRotulo }}
                </span>
            </div>
            <h3 class="font-barlow text-[1.9rem] leading-[0.95] font-extrabold tracking-normal uppercase md:text-[3.1rem]">
                {{ edital.titulo }}
            </h3>
            <!-- A descrição inteira abre a página do edital; no card, até duas linhas. -->
            <p v-if="edital.resumo" class="line-clamp-2 text-[0.9375rem] leading-[1.5] text-white/85 md:text-[1.0625rem]">{{ edital.resumo }}</p>
            <div aria-hidden="true" class="h-1 w-16 bg-white"></div>
            <dl class="flex flex-wrap gap-x-11 gap-y-4">
                <div class="flex flex-col gap-1">
                    <dt class="text-[0.75rem] font-semibold tracking-[0.16em] text-white/80 uppercase">Abertura</dt>
                    <dd class="text-[1.25rem] font-medium">{{ edital.inscricoesInicio }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[0.75rem] font-semibold tracking-[0.16em] text-white/80 uppercase">Encerramento</dt>
                    <dd class="text-[1.25rem] font-medium">{{ edital.inscricoesFim }}</dd>
                </div>
            </dl>
            <div class="slide-acao mt-1.5">
                <a
                    :href="edital.pdf"
                    :download="`edital-${edital.slug}.pdf`"
                    type="application/pdf"
                    class="pressionavel inline-flex min-h-[46px] items-center gap-3 rounded-pill px-[22px] text-[0.8125rem] font-bold tracking-[0.12em] uppercase no-underline md:min-h-[54px] md:px-8"
                    :class="edital.situacao === 'aberto' ? 'botao-claro bg-white text-home-azul' : 'vidro text-white'"
                >
                    Baixar edital<span class="sr-only">: {{ edital.titulo }} (PDF)</span>
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</template>
