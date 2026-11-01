<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AvaliacaoLayout from '../../Layouts/AvaliacaoLayout.vue';
import type { AvaliacaoInicioProps, SituacaoAvaliacao } from '../../types';

defineOptions({ layout: AvaliacaoLayout });

defineProps<AvaliacaoInicioProps>();

const avaliador = computed(() => usePage().props.avaliador);

// Cor da bolinha do selo da situação; o texto vem do servidor (situacaoRotulo).
const corSituacao: Record<SituacaoAvaliacao, string> = {
    nao_iniciada: 'bg-situacao-em-breve',
    em_andamento: 'bg-acao',
    concluida: 'bg-situacao-aberto',
};

const classeCartao = 'rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10';
const classeTermo = 'text-[0.75rem] font-semibold tracking-[0.16em] text-tinta-suave uppercase';
</script>

<template>
    <div>
        <Head title="Área do avaliador" />

        <section class="home-escuro relative overflow-hidden bg-noite pt-12 pb-36 text-white md:pt-16 md:pb-40" aria-labelledby="inicio-titulo">
            <div class="relative mx-auto flex max-w-6xl flex-col gap-3 px-4">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Área do avaliador</p>
                <h1 id="inicio-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Olá, {{ avaliador?.nome }}
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    As inscrições que você avalia, por edital. Dê as notas dentro do período de avaliação; depois de concluída, a avaliação fica só para consulta.
                </p>
            </div>
        </section>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <section v-if="editais.length === 0" aria-label="Sem inscrições" :class="classeCartao">
                <p class="text-tinta-suave">Nenhuma inscrição foi atribuída a você ainda.</p>
            </section>

            <section v-for="edital in editais" :key="edital.id" :aria-labelledby="`edital-${edital.id}`" :class="classeCartao">
                <h2 :id="`edital-${edital.id}`" class="font-barlow text-[1.5rem] leading-tight font-extrabold tracking-[0.01em] break-words uppercase md:text-[1.75rem]">
                    {{ edital.titulo }}
                </h2>
                <p class="mt-2 text-[0.9375rem] text-tinta-suave">
                    Período de avaliação: <span class="tabular-nums">{{ edital.periodos.join('; ') }}</span>
                </p>

                <ul class="mt-6 divide-y divide-hairline border-y border-hairline">
                    <li v-for="inscricao in edital.inscricoes" :key="inscricao.id" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 py-5">
                        <article :aria-labelledby="`inscricao-${inscricao.id}`" class="flex min-w-0 flex-1 flex-col gap-3">
                            <div class="flex flex-col gap-1">
                                <h3 :id="`inscricao-${inscricao.id}`" class="text-[1.0625rem] font-bold break-words">
                                    <span class="tabular-nums">{{ inscricao.numero }}</span> · {{ inscricao.proponente }}
                                </h3>
                                <p class="inline-flex items-center gap-2 text-[0.8125rem] font-semibold">
                                    <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full" :class="corSituacao[inscricao.situacao]"></span>
                                    {{ inscricao.situacaoRotulo }}
                                </p>
                            </div>
                            <dl class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                                <div class="min-w-0">
                                    <dt :class="classeTermo">Categoria</dt>
                                    <dd class="break-words">{{ inscricao.categoria ?? '—' }}</dd>
                                </div>
                                <div v-if="inscricao.notaFinal !== null">
                                    <dt :class="classeTermo">Nota final</dt>
                                    <dd class="font-semibold tabular-nums">{{ inscricao.notaFinal }}</dd>
                                </div>
                            </dl>
                        </article>
                        <!-- Caminho da rota avaliacao.inscricoes.show (o front não tem helper de rotas nomeadas). -->
                        <Link
                            :href="`/avaliacao/inscricoes/${inscricao.id}`"
                            class="pressionavel inline-flex min-h-11 items-center rounded-pill bg-acao px-5 text-[0.8125rem] font-bold text-sobre-acao no-underline hover:bg-acao-escuro"
                        >
                            {{ inscricao.situacao === 'concluida' ? 'Ver' : 'Avaliar' }}<span class="sr-only"> a inscrição {{ inscricao.numero }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
