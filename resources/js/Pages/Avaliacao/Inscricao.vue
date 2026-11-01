<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import InscricaoCompleta from '../../Components/Avaliacao/InscricaoCompleta.vue';
import NotasDosCriterios from '../../Components/Avaliacao/NotasDosCriterios.vue';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import AvaliacaoLayout from '../../Layouts/AvaliacaoLayout.vue';
import type { AvaliacaoInscricaoProps } from '../../types';

defineOptions({ layout: AvaliacaoLayout });

defineProps<AvaliacaoInscricaoProps>();

// Caminho da rota avaliacao.inicio (o front não tem helper de rotas nomeadas).
const INICIO = '/avaliacao';

const ICONE_NOTAS = 'M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z';
</script>

<template>
    <div>
        <Head :title="`Inscrição ${inscricao.numero} · Área do avaliador`" />

        <section class="home-escuro relative overflow-hidden bg-noite pt-8 pb-36 text-white md:pt-12 md:pb-40" aria-labelledby="inscricao-titulo">
            <div class="relative mx-auto flex max-w-6xl flex-col gap-3 px-4">
                <Link
                    :href="INICIO"
                    class="inline-flex min-h-11 items-center gap-2 self-start text-[0.8125rem] font-semibold tracking-[0.12em] text-white/85 uppercase no-underline hover:text-white"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    Voltar às inscrições
                </Link>
                <h1 id="inscricao-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Inscrição {{ inscricao.numero }}
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    <strong class="font-semibold text-white">{{ inscricao.edital }}</strong> · {{ situacaoRotulo }}
                </p>
            </div>
        </section>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <div v-if="aviso" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="11" width="14" height="10" rx="2" />
                    <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                </svg>
                <p class="text-[0.9375rem] leading-[1.6] font-semibold">{{ aviso }}</p>
            </div>

            <InscricaoCompleta
                :inscricao="inscricao"
                :perfil="perfil"
                :documentos="documentos"
                :secoes="secoes"
                :respostas="respostas"
                :perguntas-visiveis="perguntasVisiveis"
            />

            <SecaoCadastro id="secao-notas" :numero="4" titulo="Notas">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="ICONE_NOTAS" />
                    </svg>
                </template>

                <NotasDosCriterios
                    :inscricao-id="inscricao.id"
                    :criterios="criterios"
                    :notas="notas"
                    :observacao="observacao"
                    :pode-avaliar="podeAvaliar"
                    :nota-final="notaFinal"
                />
            </SecaoCadastro>

            <div>
                <Link :href="INICIO" class="botao-secundario">Voltar às inscrições</Link>
            </div>
        </div>
    </div>
</template>
