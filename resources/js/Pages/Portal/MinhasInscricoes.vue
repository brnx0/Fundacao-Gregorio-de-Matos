<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { PortalMinhasInscricoesProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

// Tela mínima: a lista completa entra com o novo visual do portal.
defineProps<PortalMinhasInscricoesProps>();
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-10">
        <Head title="Minhas inscrições" />

        <h1 class="text-2xl font-bold text-home-tinta">Minhas inscrições</h1>
    </div>
</template>
