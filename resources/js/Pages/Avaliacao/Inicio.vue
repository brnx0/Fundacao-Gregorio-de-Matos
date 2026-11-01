<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AvaliacaoLayout from '../../Layouts/AvaliacaoLayout.vue';

defineOptions({ layout: AvaliacaoLayout });

const avaliador = computed(() => usePage().props.avaliador);
</script>

<template>
    <div>
        <Head title="Área do avaliador" />

        <section class="home-escuro relative overflow-hidden bg-noite py-12 text-white md:py-16" aria-labelledby="inicio-titulo">
            <div class="relative mx-auto flex max-w-6xl flex-col gap-3 px-4">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Área do avaliador</p>
                <h1 id="inicio-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Olá, {{ avaliador?.nome }}
                </h1>
            </div>
        </section>
    </div>
</template>
