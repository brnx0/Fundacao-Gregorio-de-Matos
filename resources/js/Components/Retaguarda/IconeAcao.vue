<script lang="ts">
// Ícones das ações das listas da retaguarda (botões só com ícone, com a dica de data-dica): traço de 24px, como os
// demais ícones do sistema. Publicar usa o globo do grupo Portal do menu e Ocultar publicação, o mesmo globo riscado;
// Ver, o olho aberto; o questionário do edital, a prancheta com a lista.
const ICONES = {
    editar: 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497zM15 5l4 4',
    ativar: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9 12l2 2 4-4',
    desativar: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.64 5.64l12.72 12.72',
    publicar: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    ocultar: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM2 2l20 20',
    ver: 'M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
    ir: 'M5 12h14M13 6l6 6-6 6',
    abrir: 'M15 3h6v6M10 14L21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
    baixar: 'M12 15V3M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5',
    tentar: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5',
    subir: 'M12 19V5M6 11l6-6 6 6',
    descer: 'M12 5v14M6 13l6 6 6-6',
    remover: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    questionario:
        'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM12 11h4M12 16h4M8 11h.01M8 16h.01',
};

export type NomeIconeAcao = keyof typeof ICONES;
</script>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ nome: NomeIconeAcao }>();

const caminho = computed<string>(() => ICONES[props.nome]);
</script>

<template>
    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path :d="caminho" />
    </svg>
</template>
