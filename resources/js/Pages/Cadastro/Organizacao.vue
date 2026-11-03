<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import EtapasCadastro from '../../Components/EtapasCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import SaudacaoCadastro from '../../Components/SaudacaoCadastro.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { CadastroOrganizacaoProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<CadastroOrganizacaoProps>();

const finalizado = computed(() => !!usePage().props.auth.user?.tiposCadastro.length);
// Quem já tem Pessoa Jurídica vê as suas e segue; o convite para cadastrar fica para quem não tem nenhuma.
const temOrganizacoes = computed(() => props.organizacoes.length > 0);
</script>

<template>
    <div>
        <Head title="Pessoa Jurídica" />

        <FaixaPortal aria-labelledby="organizacao-titulo">
            <!-- No modo edição, o mesmo cabeçalho das outras etapas. -->
            <SaudacaoCadastro v-if="finalizado" id-titulo="organizacao-titulo" />
            <div v-else class="flex flex-col gap-3">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Etapa 4 de 5 · Pessoa Jurídica</p>
                <h1 id="organizacao-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    {{ temOrganizacoes ? 'Suas Pessoas Jurídicas' : 'Você representa uma Pessoa Jurídica?' }}
                </h1>
            </div>
            <template #abaixo>
                <EtapasCadastro :atual="4" :navegavel="finalizado" />
            </template>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 max-w-6xl px-4 pb-16 md:-mt-24">
            <section
                v-if="temOrganizacoes"
                aria-labelledby="organizacao-lista-titulo"
                class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10"
            >
                <h2 id="organizacao-lista-titulo" class="max-w-2xl leading-[1.6] text-tinta-suave">
                    {{ props.organizacoes.length === 1 ? 'Você já está vinculado a esta Pessoa Jurídica:' : 'Você já está vinculado a estas Pessoas Jurídicas:' }}
                </h2>

                <ul class="mt-6 flex flex-col gap-3">
                    <li
                        v-for="organizacao in props.organizacoes"
                        :key="organizacao.id"
                        class="flex flex-col gap-3 rounded-[18px] border border-hairline p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 items-center gap-4">
                            <img
                                v-if="organizacao.logoUrl"
                                :src="organizacao.logoUrl"
                                alt=""
                                class="size-12 shrink-0 rounded-[12px] border border-hairline bg-white object-contain"
                            />
                            <span v-else aria-hidden="true" class="inline-flex size-12 shrink-0 items-center justify-center rounded-[12px] bg-nevoa text-acao">
                                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 21V6l8-3 8 3v15M9 21v-5h6v5M8 9h.01M12 9h.01M16 9h.01M8 13h.01M12 13h.01M16 13h.01" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold break-words text-tinta">{{ organizacao.nome }}</p>
                                <p class="text-[0.875rem] text-tinta-suave">
                                    CNPJ <span class="tabular-nums">{{ organizacao.cnpj }}</span>
                                    <span v-if="organizacao.responsavel" class="ml-2 inline-flex rounded-pill bg-nevoa px-2 py-0.5 text-[0.6875rem] font-bold tracking-[0.08em] text-acao uppercase">
                                        Responsável
                                    </span>
                                </p>
                            </div>
                        </div>
                        <Link
                            :href="`/organizacoes/${organizacao.id}/editar`"
                            class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-pill border border-hairline px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-tinta uppercase no-underline hover:border-acao hover:text-acao"
                        >
                            Editar<span class="sr-only"> {{ organizacao.nome }}</span>
                        </Link>
                    </li>
                </ul>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <!-- Segue para a última etapa (tipo de cadastro). -->
                    <Link
                        href="/cadastro/tipo"
                        class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill bg-acao px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase no-underline hover:bg-acao-escuro"
                    >
                        Continuar
                    </Link>
                    <Link
                        href="/cadastro/pessoa-juridica"
                        class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill border border-hairline px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-tinta uppercase no-underline"
                    >
                        Cadastrar outra Pessoa Jurídica
                    </Link>
                </div>
            </section>

            <section
                v-else
                aria-labelledby="organizacao-opcoes-titulo"
                class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10"
            >
                <h2 id="organizacao-opcoes-titulo" class="sr-only">Escolha como seguir</h2>
                <p class="max-w-2xl leading-[1.6] text-tinta-suave">
                    Cadastre a Pessoa Jurídica (CNPJ) que você representa no setor cultural. Assim, ela também pode se inscrever nos editais.
                </p>
                <p id="organizacao-tipos" class="mt-6 font-semibold text-tinta">Pode ser:</p>
                <ul aria-labelledby="organizacao-tipos" class="mt-2 flex list-disc flex-col gap-1 pl-5 leading-[1.6] text-tinta-suave marker:text-acao">
                    <li>MEI (Microempreendedor Individual)</li>
                    <li>Empresa com fins lucrativos</li>
                    <li>Associação, coletivo ou outra entidade sem fins lucrativos</li>
                </ul>
                <p class="mt-6 max-w-2xl leading-[1.6] text-tinta-suave">Não é obrigatório: se preferir, cadastre depois em Minhas Pessoas Jurídicas.</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <Link
                        href="/cadastro/pessoa-juridica"
                        class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill bg-acao px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase no-underline hover:bg-acao-escuro"
                    >
                        Cadastrar Pessoa Jurídica
                    </Link>
                    <!-- Pula para a última etapa (tipo de cadastro). -->
                    <Link
                        href="/cadastro/tipo"
                        class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill border border-hairline px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-tinta uppercase no-underline"
                    >
                        Não represento
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
