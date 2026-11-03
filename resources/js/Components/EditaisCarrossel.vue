<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConteudoSlideEdital from './ConteudoSlideEdital.vue';
import { fotosVitrine, fotoVitrine } from './fotosVitrine';
import type { EditalResumo } from '../types';

const props = defineProps<{
    editais: EditalResumo[];
}>();

const ID_SLIDES = 'editais-slides';
const INTERVALO_MS = 7000;
// O leque do anexo tem cinco cards (o do centro e dois de cada lado).
const CARDS_NO_LEQUE = 5;
// Deslocamento horizontal mínimo para o gesto contar como troca de slide.
const ARRASTO_MINIMO_PX = 50;

// Fotos da vitrine: a reserva para o edital sem imagem cadastrada, pela posição do card na roda.
const fotos = fotosVitrine;
// A imagem enviada na retaguarda tem prioridade; centralizada, já que o enquadramento dela não é conhecido.
const foto = (indice: number): { src: string; enquadramento: string } => {
    const imagem = slides.value[indice]?.edital.imagem;
    return imagem ? { src: imagem, enquadramento: 'center' } : fotoVitrine(indice);
};

const regiao = ref<HTMLElement | null>(null);
const total = computed(() => props.editais.length);

/** Menor número de voltas da lista para editais e fotos fecharem o ciclo juntos na roda. */
const voltasAteFecharFotos = (n: number): number => {
    let voltas = 1;
    while ((voltas * n) % fotos.length !== 0) voltas++;
    return voltas;
};

// Com menos editais que cards no leque, a lista se repete na roda para as laterais não ficarem vazias;
// fechando o ciclo junto com o das fotos, os cards à vista nunca repetem foto.
const slides = computed(() => {
    const copias = total.value > 1 && total.value < CARDS_NO_LEQUE ? voltasAteFecharFotos(total.value) : 1;

    return Array.from({ length: total.value * copias }, (_, i) => ({
        edital: props.editais[i % total.value],
        indiceEdital: i % total.value,
        chave: `${props.editais[i % total.value].slug}-${Math.floor(i / total.value)}`,
    }));
});
const tamanhoRoda = computed(() => slides.value.length);

// Índice em slides, não em editais.
const ativo = ref<number>(0);
const editalAtivo = computed(() => slides.value[ativo.value]?.indiceEdital ?? 0);

// Abaixo de md só o vizinho de cada lado aparece; com movimento reduzido não há troca automática.
const estreito = ref<boolean>(false);
const reduzMovimento = ref<boolean>(false);

/** Distância até o slide ativo pelo caminho mais curto da roda: 0 no centro, ±1 e ±2 nas laterais. */
const posicao = (indice: number): number => {
    const n = tamanhoRoda.value;
    let distancia = (((indice - ativo.value) % n) + n) % n;
    if (distancia > n / 2) distancia -= n;
    return distancia;
};
const visivel = (indice: number): boolean => Math.abs(posicao(indice)) <= (estreito.value ? 1 : 2);

/** Para leitores de tela cada edital aparece uma vez só: na cópia mais perto do centro (a do centro é sempre ela). */
const principais = computed(() => {
    const escolhidos = new Map<number, number>();
    slides.value.forEach((slide, i) => {
        const atual = escolhidos.get(slide.indiceEdital);
        if (atual === undefined || Math.abs(posicao(i)) < Math.abs(posicao(atual))) escolhidos.set(slide.indiceEdital, i);
    });

    return new Set(escolhidos.values());
});

// Troca automática (anexo: 7 s), suspensa com o mouse ou o foco no carrossel.
const mouseDentro = ref<boolean>(false);
const focoDentro = ref<boolean>(false);
const rodando = computed(() => total.value > 1 && !reduzMovimento.value && !mouseDentro.value && !focoDentro.value);

// Os slides laterais continuam visíveis, então a troca é anunciada por este texto (só quando o usuário troca).
const anuncio = ref<string>('');

let relogio: number | undefined;

const reiniciarRelogio = (): void => {
    window.clearInterval(relogio);
    relogio = rodando.value ? window.setInterval(() => irPara(ativo.value + 1, true), INTERVALO_MS) : undefined;
};

const irPara = (indice: number, automatico = false): void => {
    if (!total.value) return;
    ativo.value = ((indice % tamanhoRoda.value) + tamanhoRoda.value) % tamanhoRoda.value;
    if (!automatico) anuncio.value = `Edital ${editalAtivo.value + 1} de ${total.value}: ${props.editais[editalAtivo.value].titulo}`;
    reiniciarRelogio();
};

// O botão "Mostrar…" some quando o slide vira o ativo: o foco passa para o link dele.
const escolher = (indice: number): void => {
    irPara(indice);
    nextTick(() => regiao.value?.querySelector<HTMLElement>('[data-posicao="0"] .slide-acao a')?.focus({ preventScroll: true }));
};

const aoTeclar = (evento: KeyboardEvent): void => {
    if (evento.key !== 'ArrowLeft' && evento.key !== 'ArrowRight') return;
    evento.preventDefault();
    irPara(ativo.value + (evento.key === 'ArrowRight' ? 1 : -1));
};

const aoSairFoco = (evento: FocusEvent): void => {
    focoDentro.value = regiao.value?.contains(evento.relatedTarget as Node | null) ?? false;
};

// Arrastar para os lados (toque ou mouse) troca o slide; o clique que encerra o gesto é descartado.
let inicioX: number | null = null;
let arrastou = false;

const aoPressionar = (evento: PointerEvent): void => {
    if (!evento.isPrimary || evento.button !== 0) return;
    inicioX = evento.clientX;
    arrastou = false;
};

const aoSoltar = (evento: PointerEvent): void => {
    if (inicioX === null) return;
    const deslocamento = evento.clientX - inicioX;
    inicioX = null;
    if (total.value < 2 || Math.abs(deslocamento) < ARRASTO_MINIMO_PX) return;
    arrastou = true;
    // O clique do gesto vem logo após o pointerup; um toque que só arrastou não gera clique,
    // e a marca não pode sobrar para o próximo clique (ou Enter) de verdade.
    window.setTimeout(() => {
        arrastou = false;
    });
    irPara(ativo.value + (deslocamento < 0 ? 1 : -1));
};

const cancelarArrasto = (): void => {
    inicioX = null;
};

const descartarCliqueDoArrasto = (evento: MouseEvent): void => {
    if (!arrastou) return;
    arrastou = false;
    evento.preventDefault();
    evento.stopPropagation();
};

let consultaEstreita: MediaQueryList | undefined;
let consultaMovimento: MediaQueryList | undefined;

const lerMidia = (): void => {
    estreito.value = consultaEstreita?.matches ?? false;
    reduzMovimento.value = consultaMovimento?.matches ?? false;
};

watch(rodando, reiniciarRelogio);
watch(tamanhoRoda, () => {
    if (ativo.value >= tamanhoRoda.value) ativo.value = 0;
    reiniciarRelogio();
});

onMounted(() => {
    consultaEstreita = window.matchMedia('(width < 48rem)');
    consultaMovimento = window.matchMedia('(prefers-reduced-motion: reduce)');
    consultaEstreita.addEventListener('change', lerMidia);
    consultaMovimento.addEventListener('change', lerMidia);
    lerMidia();
    reiniciarRelogio();
});

onBeforeUnmount(() => {
    window.clearInterval(relogio);
    consultaEstreita?.removeEventListener('change', lerMidia);
    consultaMovimento?.removeEventListener('change', lerMidia);
});
</script>

<template>
    <div
        ref="regiao"
        role="region"
        aria-roledescription="carrossel"
        aria-label="Editais em destaque"
        @keydown="aoTeclar"
        @mouseenter="mouseDentro = true"
        @mouseleave="mouseDentro = false"
        @focusin="focoDentro = true"
        @focusout="aoSairFoco"
    >
        <!-- A partir de md o card acompanha a largura da tela (até 500px) para o leque caber inteiro, com espaço branco dos lados;
             o palco acompanha a altura dele (20/29). -->
        <div
            class="relative h-[540px] touch-pan-y select-none md:h-[calc(var(--largura-card)*1.45_+_65px)] md:[--largura-card:clamp(360px,26vw,500px)]"
            @pointerdown="aoPressionar"
            @pointerup="aoSoltar"
            @pointercancel="cancelarArrasto"
            @click.capture="descartarCliqueDoArrasto"
            @dragstart.prevent
        >
            <template v-if="total > 1">
                <button
                    type="button"
                    class="vidro vidro-escuro pressionavel absolute top-1/2 left-0 z-10 -mt-6 inline-flex size-12 items-center justify-center rounded-full text-white md:-mt-8 md:size-16"
                    aria-label="Edital anterior"
                    :aria-controls="ID_SLIDES"
                    @click="irPara(ativo - 1)"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 18l-6-6 6-6" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="vidro vidro-escuro pressionavel absolute top-1/2 right-0 z-10 -mt-6 inline-flex size-12 items-center justify-center rounded-full text-white md:-mt-8 md:size-16"
                    aria-label="Próximo edital"
                    :aria-controls="ID_SLIDES"
                    @click="irPara(ativo + 1)"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </button>
            </template>

            <div :id="ID_SLIDES">
                <article
                    v-for="({ edital, indiceEdital, chave }, i) in slides"
                    :key="chave"
                    class="slide-edital absolute top-1/2 left-1/2 grid w-[300px] max-w-[80%] md:w-(--largura-card)"
                    :data-posicao="visivel(i) ? posicao(i) : 'fora'"
                    :inert="!visivel(i)"
                    :aria-hidden="principais.has(i) ? undefined : 'true'"
                    aria-roledescription="slide"
                    :aria-label="`${indiceEdital + 1} de ${total}: ${edital.titulo}`"
                >
                    <ConteudoSlideEdital :edital="edital" :foto="foto(i)" />
                    <button
                        v-if="posicao(i) !== 0"
                        type="button"
                        class="absolute inset-0 z-[5] cursor-pointer"
                        :tabindex="principais.has(i) ? undefined : -1"
                        :aria-label="`Mostrar ${edital.titulo}`"
                        @click="escolher(i)"
                    ></button>
                </article>
            </div>
        </div>

        <p class="sr-only" aria-live="polite">{{ anuncio }}</p>
    </div>
</template>
