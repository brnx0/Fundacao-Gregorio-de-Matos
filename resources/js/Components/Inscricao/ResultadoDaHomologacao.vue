<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ResultadoDaHomologacao } from '../../types';

defineProps<{
    resultado: ResultadoDaHomologacao;
    inscricaoId: number;
}>();

const classeCaixa = 'flex flex-col gap-3 rounded-[16px] border p-4 md:p-5';
const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
</script>

<template>
    <!-- Resultado da homologação na revisão da inscrição finalizada (InscricaoController@show). -->
    <section aria-labelledby="resultado-homologacao-titulo" class="flex flex-col gap-3">
        <h4 id="resultado-homologacao-titulo" class="font-bold">Resultado da homologação</h4>

        <p v-if="resultado.situacao === 'em_homologacao'" class="leading-[1.6] text-tinta-suave">
            A equipe vai conferir a inscrição na homologação. O resultado aparece aqui.
        </p>

        <div v-else-if="resultado.situacao === 'homologada'" :class="[classeCaixa, 'border-hairline']">
            <p class="flex items-start gap-2 font-semibold text-texto-verde">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12 5 5 9-10" />
                </svg>
                Inscrição homologada.
            </p>
            <!-- Só quando a homologação veio do recurso aceito. -->
            <dl v-if="resultado.parecer">
                <dt :class="classeTermo">Parecer do recurso</dt>
                <dd class="break-words whitespace-pre-line">{{ resultado.parecer }}</dd>
            </dl>
        </div>

        <p v-else-if="resultado.situacao === 'em_recurso'" class="leading-[1.6] text-tinta-suave">Recebemos o seu recurso. A equipe vai analisá-lo.</p>

        <div v-else :class="[classeCaixa, 'border-erro-borda bg-erro-fundo text-erro-texto']">
            <p class="flex items-start gap-2 font-semibold">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="9" />
                    <path d="m15 9-6 6M9 9l6 6" />
                </svg>
                {{ resultado.situacao === 'recurso_negado' ? 'Não homologada (recurso negado).' : 'Inscrição não homologada.' }}
            </p>

            <dl v-if="resultado.motivo || resultado.itens.length > 0 || (resultado.situacao === 'recurso_negado' && resultado.parecer)" class="flex flex-col gap-3">
                <div v-if="resultado.motivo">
                    <dt class="text-[0.8125rem] font-semibold">Motivo</dt>
                    <dd class="break-words whitespace-pre-line">{{ resultado.motivo }}</dd>
                </div>
                <div v-if="resultado.itens.length > 0">
                    <dt class="text-[0.8125rem] font-semibold">O que foi apontado</dt>
                    <dd>
                        <ul class="mt-1 list-disc space-y-1 pl-6">
                            <li v-for="(item, indice) in resultado.itens" :key="indice" class="break-words">
                                {{ item.nome }}<template v-if="item.observacao"> — {{ item.observacao }}</template>
                            </li>
                        </ul>
                    </dd>
                </div>
                <div v-if="resultado.situacao === 'recurso_negado' && resultado.parecer">
                    <dt class="text-[0.8125rem] font-semibold">Parecer do recurso</dt>
                    <dd class="break-words whitespace-pre-line">{{ resultado.parecer }}</dd>
                </div>
            </dl>

            <template v-if="resultado.situacao === 'nao_homologada'">
                <p v-if="resultado.podeRecorrer" class="leading-[1.6]">Prazo para recurso: até {{ resultado.prazoDoRecurso }}, às 23:59.</p>
                <p v-else class="leading-[1.6]">O prazo para recurso terminou.</p>
                <div v-if="resultado.podeRecorrer" class="flex">
                    <!-- Caminho da rota portal.inscricoes.recurso.create (o front não tem helper de rotas nomeadas). -->
                    <Link
                        :href="`/portal/inscricoes/${inscricaoId}/recurso`"
                        class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill bg-acao px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase no-underline hover:bg-acao-escuro"
                    >
                        Entrar com recurso
                    </Link>
                </div>
            </template>
        </div>
    </section>
</template>
