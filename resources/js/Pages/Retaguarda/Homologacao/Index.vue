<script setup lang="ts">
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import { Head, Link } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaHomologacaoIndexProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

defineProps<RetaguardaHomologacaoIndexProps>();

// Caminho da rota retaguarda.homologacao.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/homologacao';
</script>

<template>
    <PaginaRetaguarda titulo="Homologação" descricao="Inscrições finalizadas, para conferir e decidir.">
        <Head title="Homologação · Retaguarda" />

        <!-- Versão mínima: filtros, tabela e selos entram na tela no visual novo. -->
        <ul>
            <li v-for="inscricao in inscricoes.data" :key="inscricao.id">
                <Link :href="`${BASE}/${inscricao.id}`">{{ inscricao.numero }} · {{ inscricao.proponente }} · {{ inscricao.edital }} · {{ inscricao.situacaoRotulo }}</Link>
            </li>
        </ul>
    </PaginaRetaguarda>
</template>
