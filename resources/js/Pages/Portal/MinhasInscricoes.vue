<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { PortalMinhasInscricoesProps, SituacaoNoPortal } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

defineProps<PortalMinhasInscricoesProps>();

// Cor da bolinha do selo da situação; o texto do selo vem do servidor (situacaoRotulo).
const corSituacao: Record<SituacaoNoPortal, string> = {
    rascunho: 'bg-situacao-encerrado',
    em_homologacao: 'bg-acao',
    em_recurso: 'bg-situacao-em-breve',
    homologada: 'bg-situacao-aberto',
    nao_homologada: 'bg-invalido-texto',
    recurso_negado: 'bg-invalido-texto',
};

const classeCartao = 'rounded-[24px] border border-hairline bg-superficie shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)]';
const classeTermo = 'text-[0.75rem] font-semibold tracking-[0.16em] text-tinta-suave uppercase';
</script>

<template>
    <div>
        <Head title="Minhas inscrições" />

        <FaixaPortal aria-labelledby="minhas-inscricoes-titulo">
            <div class="flex flex-col gap-3">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Portal do proponente</p>
                <h1 id="minhas-inscricoes-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Minhas inscrições
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    As inscrições que você fez nos editais, das mais novas para as mais antigas, com o resultado da homologação.
                </p>
            </div>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <section v-if="inscricoes.length > 0" aria-label="Lista das inscrições" :class="[classeCartao, 'p-6 md:p-10']">
                <ul class="divide-y divide-hairline border-y border-hairline">
                    <li v-for="inscricao in inscricoes" :key="inscricao.id" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 py-5">
                        <article :aria-labelledby="`inscricao-${inscricao.id}`" class="flex min-w-0 flex-1 flex-col gap-3">
                            <div class="flex flex-col gap-1">
                                <h2 :id="`inscricao-${inscricao.id}`" class="text-[1.0625rem] font-bold break-words">{{ inscricao.edital }}</h2>
                                <p class="inline-flex items-center gap-2 text-[0.8125rem] font-semibold">
                                    <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full" :class="corSituacao[inscricao.situacao]"></span>
                                    {{ inscricao.situacaoRotulo }}
                                </p>
                            </div>
                            <dl class="grid gap-x-8 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <dt :class="classeTermo">Número</dt>
                                    <dd class="tabular-nums">{{ inscricao.numero ?? '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt :class="classeTermo">Cadastro</dt>
                                    <dd class="break-words">{{ inscricao.cadastro }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt :class="classeTermo">Categoria</dt>
                                    <dd class="break-words">{{ inscricao.categoria ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt :class="classeTermo">Enviada em</dt>
                                    <dd class="tabular-nums">{{ inscricao.finalizadaEm ?? 'Não enviada' }}</dd>
                                </div>
                            </dl>
                        </article>
                        <!-- Caminho da rota portal.inscricoes.show (o front não tem helper de rotas nomeadas). -->
                        <Link
                            :href="`/portal/inscricoes/${inscricao.id}`"
                            class="pressionavel inline-flex min-h-11 items-center rounded-pill bg-acao px-5 text-[0.8125rem] font-bold text-sobre-acao no-underline hover:bg-acao-escuro"
                        >
                            Abrir<span class="sr-only"> a inscrição no edital {{ inscricao.edital }}<template v-if="inscricao.numero">, nº {{ inscricao.numero }}</template></span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section v-else aria-labelledby="minhas-inscricoes-vazio" :class="[classeCartao, 'flex flex-col items-center gap-4 px-6 py-14 text-center md:py-20']">
                <span aria-hidden="true" class="inline-flex size-14 items-center justify-center rounded-full bg-nevoa text-acao">
                    <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1zM8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 14l2 2 4-4" />
                    </svg>
                </span>
                <h2 id="minhas-inscricoes-vazio" class="text-[1.25rem] font-bold text-home-tinta">Você ainda não tem inscrições.</h2>
                <!-- Caminho da rota portal.editais (o front não tem helper de rotas nomeadas). -->
                <Link
                    href="/portal/editais"
                    class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill bg-acao px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase no-underline hover:bg-acao-escuro"
                >
                    Ver os editais
                </Link>
            </section>
        </div>
    </div>
</template>
