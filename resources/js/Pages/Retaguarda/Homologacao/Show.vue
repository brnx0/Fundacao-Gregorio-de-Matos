<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaHomologacaoShowProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

defineProps<RetaguardaHomologacaoShowProps>();
</script>

<template>
    <PaginaRetaguarda :titulo="`Inscrição ${inscricao.numero}`" :descricao="`${inscricao.proponente} · ${inscricao.edital}`" :trilha="[{ texto: inscricao.numero }]">
        <Head :title="`Inscrição ${inscricao.numero} · Homologação · Retaguarda`" />

        <!-- Versão mínima: a conferência (documentos, respostas, marcações e decisão) entra na tela no visual novo. -->
        <p v-if="aviso" role="status">{{ aviso }}</p>
        <p>{{ inscricao.situacaoRotulo }}</p>
    </PaginaRetaguarda>
</template>
