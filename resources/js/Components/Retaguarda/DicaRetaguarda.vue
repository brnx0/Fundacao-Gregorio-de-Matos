<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

// Dica dos botões que só têm ícone: o elemento com data-dica="Editar" mostra o texto ao passar o mouse e ao receber o
// foco pelo teclado (Esc fecha); data-dica-lado="direita" põe a dica ao lado, e não em cima (menu lateral recolhido).
// Uma dica só para a retaguarda inteira, aberta no topo da tela (popover): não é cortada pela rolagem das tabelas nem
// fica atrás do menu. Não pega o mouse, para nunca cobrir o botão da linha de cima. O nome acessível continua no
// próprio botão (texto sr-only, que começa pelo texto da dica), então a dica fica escondida do leitor de tela.
const ESPERA_MS = 250;
/** Logo depois de uma dica sumir, a próxima aparece sem espera (ao correr o mouse pelos ícones de uma linha). */
const EMENDA_MS = 400;
const MARGEM_PX = 8;

const dica = ref<HTMLElement | null>(null);

let alvo: HTMLElement | null = null;
let pendente: HTMLElement | null = null;
let temporizador: ReturnType<typeof setTimeout> | undefined;
let sumiuEm = 0;

const suportaPopover = 'showPopover' in HTMLElement.prototype;

const comDica = (no: EventTarget | null): HTMLElement | null => (no instanceof Element ? no.closest<HTMLElement>('[data-dica]') : null);

// Em cima e centralizada (embaixo quando falta espaço), ou à direita; sempre dentro da tela.
const posicionar = (elemento: HTMLElement, caixa: HTMLElement): void => {
    // Mede no canto da tela: no lugar da dica anterior, perto da borda direita, o texto quebraria antes da hora e a
    // caixa, já no lugar novo, cresceria até a borda.
    caixa.style.left = '0px';
    caixa.style.top = '0px';
    const referencia = elemento.getBoundingClientRect();
    const largura = caixa.offsetWidth;
    const altura = caixa.offsetHeight;
    let esquerda: number;
    let topo: number;

    // A dica surge do lado do botão (transform-origin).
    if (elemento.dataset.dicaLado === 'direita') {
        esquerda = referencia.right + MARGEM_PX;
        topo = referencia.top + referencia.height / 2 - altura / 2;
        caixa.style.transformOrigin = 'left center';
    } else {
        esquerda = referencia.left + referencia.width / 2 - largura / 2;
        topo = referencia.top - altura - MARGEM_PX;
        caixa.style.transformOrigin = 'bottom center';
        if (topo < MARGEM_PX) {
            topo = referencia.bottom + MARGEM_PX;
            caixa.style.transformOrigin = 'top center';
        }
    }

    const { clientWidth, clientHeight } = document.documentElement;
    caixa.style.left = `${Math.round(Math.min(Math.max(esquerda, MARGEM_PX), clientWidth - largura - MARGEM_PX))}px`;
    caixa.style.top = `${Math.round(Math.min(Math.max(topo, MARGEM_PX), clientHeight - altura - MARGEM_PX))}px`;
};

/** surgir: só a dica que esperou o mouse anima; a emendada e a do foco pelo teclado aparecem na hora. */
const mostrar = (elemento: HTMLElement, surgir = false): void => {
    const caixa = dica.value;
    const texto = elemento.dataset.dica;
    clearTimeout(temporizador);
    pendente = null;
    // Com o menu do botão aberto, a dica sobraria.
    if (!caixa || !texto || elemento.getAttribute('aria-expanded') === 'true') return;

    alvo = elemento;
    caixa.textContent = texto;
    if (surgir) {
        caixa.dataset.surgir = '';
    } else {
        delete caixa.dataset.surgir;
    }
    caixa.dataset.visivel = '';
    if (suportaPopover && !caixa.matches(':popover-open')) caixa.showPopover();
    posicionar(elemento, caixa);
};

const esconder = (): void => {
    clearTimeout(temporizador);
    pendente = null;
    if (alvo === null) return;

    alvo = null;
    sumiuEm = Date.now();
    const caixa = dica.value;
    if (!caixa) return;
    delete caixa.dataset.visivel;
    if (suportaPopover && caixa.matches(':popover-open')) caixa.hidePopover();
};

const agendar = (elemento: HTMLElement): void => {
    if (elemento === alvo || elemento === pendente) return;
    if (alvo !== null || Date.now() - sumiuEm < EMENDA_MS) {
        mostrar(elemento);
        return;
    }
    clearTimeout(temporizador);
    pendente = elemento;
    temporizador = setTimeout(() => mostrar(elemento, true), ESPERA_MS);
};

// No toque não há "passar o mouse": o toque já aciona o botão.
const aoEntrar = (evento: PointerEvent): void => {
    if (evento.pointerType === 'touch') return;
    const elemento = comDica(evento.target);
    if (elemento) agendar(elemento);
};

const aoSair = (evento: PointerEvent): void => {
    const elemento = comDica(evento.target);
    // Ainda dentro do mesmo botão (passou do fundo para o ícone).
    if (!elemento || (evento.relatedTarget instanceof Node && elemento.contains(evento.relatedTarget))) return;
    if (elemento === pendente) {
        clearTimeout(temporizador);
        pendente = null;
    }
    // Com o foco do teclado nele, a dica fica.
    if (elemento === alvo && !elemento.matches(':focus-visible')) esconder();
};

// Só o foco do teclado: o clique do mouse também foca o botão, e aí a dica já sumiu no pointerdown.
const aoFocar = (evento: FocusEvent): void => {
    const elemento = comDica(evento.target);
    if (elemento?.matches(':focus-visible')) mostrar(elemento);
};

const aoDesfocar = (evento: FocusEvent): void => {
    if (alvo !== null && evento.target === alvo) esconder();
};

const aoTeclar = (evento: KeyboardEvent): void => {
    if (evento.key === 'Escape') esconder();
};

const pararDeOuvir = router.on('start', esconder);

onMounted(() => {
    document.addEventListener('pointerover', aoEntrar);
    document.addEventListener('pointerout', aoSair);
    document.addEventListener('focusin', aoFocar);
    document.addEventListener('focusout', aoDesfocar);
    document.addEventListener('keydown', aoTeclar);
    document.addEventListener('pointerdown', esconder, true);
    // A posição é da tela: rolar (a página ou uma tabela) ou redimensionar tira a dica do lugar.
    window.addEventListener('scroll', esconder, { capture: true, passive: true });
    window.addEventListener('resize', esconder);
});

onBeforeUnmount(() => {
    pararDeOuvir();
    clearTimeout(temporizador);
    document.removeEventListener('pointerover', aoEntrar);
    document.removeEventListener('pointerout', aoSair);
    document.removeEventListener('focusin', aoFocar);
    document.removeEventListener('focusout', aoDesfocar);
    document.removeEventListener('keydown', aoTeclar);
    document.removeEventListener('pointerdown', esconder, true);
    window.removeEventListener('scroll', esconder, { capture: true });
    window.removeEventListener('resize', esconder);
});
</script>

<template>
    <div ref="dica" popover="manual" aria-hidden="true" class="dica-retaguarda"></div>
</template>
