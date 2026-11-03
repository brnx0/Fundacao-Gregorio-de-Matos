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
    <!-- Card da vitrine (EditaisCarrossel; também na prévia da retaguarda). O <article> do slide só dá a posição, o tamanho
         e a animação; o visual inteiro fica aqui, para a prévia não divergir do carrossel. -->
    <div
        class="cartao-vitrine home-escuro @container relative grid h-full grid-cols-1 overflow-hidden rounded-[24px] bg-home-marinho font-montserrat text-white shadow-[0_50px_90px_-40px_rgba(10,20,40,0.6)] ring-1 ring-white/10 ring-inset"
    >
        <!-- A foto ocupa a parte de cima e se funde no azul-marinho do card: o véu escurece a área do título e a máscara
             apaga a borda de baixo (sem fio de foto entre o véu e o fundo). -->
        <div class="absolute inset-x-0 top-0 h-[64%] overflow-hidden [mask-image:linear-gradient(180deg,#000_80%,transparent)]">
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
                class="home-veu absolute inset-0 bg-[linear-gradient(180deg,rgba(7,20,44,0)_25%,rgba(7,20,44,0.6)_52%,rgba(7,20,44,0.92)_78%,rgba(7,20,44,1)_100%)]"
            ></div>
        </div>

        <!-- O espaçador dá a proporção 20/29 do modelo; se o texto for mais alto (título longo, A+), o card cresce. -->
        <div aria-hidden="true" class="col-start-1 row-start-1 aspect-[20/29]"></div>
        <div class="slide-texto relative col-start-1 row-start-1 flex flex-col p-6 @sm:p-8">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-pill bg-home-amarelo px-3 py-1.5 text-[0.6875rem] leading-none font-bold tracking-[0.14em] text-home-marinho uppercase @sm:text-[0.75rem]">
                    {{ edital.categoria }}
                </span>
                <span
                    class="vidro vidro-escuro vidro-pilula inline-flex items-center gap-2 rounded-pill bg-home-marinho/55 px-3 py-1.5 text-[0.75rem] leading-none font-semibold @sm:text-[0.8125rem]"
                >
                    <span aria-hidden="true" class="size-2 rounded-full" :class="situacoes[edital.situacao].cor"></span>
                    {{ situacoes[edital.situacao].rotulo ?? edital.situacaoRotulo }}
                </span>
            </div>

            <div class="mt-auto flex flex-col gap-3 pt-10 @sm:gap-4">
                <div aria-hidden="true" class="h-[3px] w-9 bg-home-amarelo @sm:w-10"></div>
                <h3 class="font-barlow text-[clamp(1.75rem,10.6cqi,3.25rem)] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase">
                    {{ edital.titulo }}
                </h3>
                <!-- A descrição inteira abre a página do edital; no card, até duas linhas. -->
                <p v-if="edital.resumo" class="line-clamp-2 text-[0.875rem] leading-[1.45] text-white/80 @sm:text-[1rem]">{{ edital.resumo }}</p>

                <!-- Metade para cada data; no card estreito do celular o "Encerramento" pede mais espaço e, se nem assim
                     couber (A+), desce para a linha de baixo. -->
                <dl class="mt-1 flex flex-wrap gap-y-3 border-t border-white/10 pt-4">
                    <div class="flex flex-1 flex-col gap-1.5 pr-3 @xs:pr-4">
                        <dt class="text-[0.625rem] font-bold tracking-[0.12em] text-home-amarelo uppercase @xs:text-[0.6875rem] @xs:tracking-[0.16em]">Abertura</dt>
                        <dd class="font-barlow text-[clamp(1rem,6.2cqi,1.75rem)] leading-none font-bold tabular-nums">{{ edital.inscricoesInicio }}</dd>
                    </div>
                    <div class="flex flex-1 flex-col gap-1.5 border-l border-white/15 pl-3 @xs:pl-4 @sm:pl-5">
                        <dt class="text-[0.625rem] font-bold tracking-[0.12em] text-home-amarelo uppercase @xs:text-[0.6875rem] @xs:tracking-[0.16em]">Encerramento</dt>
                        <dd class="font-barlow text-[clamp(1rem,6.2cqi,1.75rem)] leading-none font-bold tabular-nums">{{ edital.inscricoesFim }}</dd>
                    </div>
                </dl>

                <div class="slide-acao mt-2">
                    <a
                        :href="edital.pdf"
                        :download="`edital-${edital.slug}.pdf`"
                        type="application/pdf"
                        class="botao-claro pressionavel flex min-h-[50px] w-full items-center justify-between gap-3 rounded-pill bg-white py-1.5 pr-1.5 pl-6 text-[0.75rem] font-bold tracking-[0.18em] text-home-marinho uppercase no-underline hover:bg-[#e8edf5] @sm:min-h-[56px] @sm:pl-7"
                    >
                        <span>Baixar edital<span class="sr-only">: {{ edital.titulo }} (PDF)</span></span>
                        <span aria-hidden="true" class="cartao-vitrine-icone grid size-9 shrink-0 place-items-center rounded-full bg-home-amarelo @sm:size-10">
                            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
                            </svg>
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
