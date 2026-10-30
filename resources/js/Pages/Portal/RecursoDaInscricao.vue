<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { PortalRecursoDaInscricaoProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

// Tela mínima: o formulário do recurso entra com o novo visual do portal.
defineProps<PortalRecursoDaInscricaoProps>();
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-10">
        <Head title="Recurso da inscrição" />

        <h1 class="text-2xl font-bold text-home-tinta">Recurso da inscrição</h1>
    </div>
</template>
