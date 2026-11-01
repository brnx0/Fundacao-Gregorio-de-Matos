<script setup lang="ts">
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import { Head, Link } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaAvaliacaoNotasDaInscricaoProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliacaoNotasDaInscricaoProps>();

// Caminho da rota retaguarda.avaliacao.notas.index (o front não tem helper de rotas nomeadas).
const VOLTAR = `/retaguarda/avaliacao/editais/${props.edital.slug}/notas`;

const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
</script>

<template>
    <PaginaRetaguarda
        :titulo="`Notas da inscrição ${inscricao.numero}`"
        :descricao="edital.titulo"
        :trilha="[{ texto: edital.titulo }, { texto: `Inscrição ${inscricao.numero}` }]"
    >
        <Head :title="`Notas da inscrição ${inscricao.numero} · ${edital.titulo} · Retaguarda`" />

        <template #acoes>
            <Link
                :href="VOLTAR"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M11 18l-6-6 6-6" />
                </svg>
                Voltar para as notas
            </Link>
        </template>

        <section aria-label="Resumo da avaliação" class="painel-retaguarda p-5 @xl:p-6">
            <dl class="grid gap-x-6 gap-y-4 text-[0.9375rem] @xl:grid-cols-2 @3xl:grid-cols-3">
                <div>
                    <dt :class="classeTermo">Proponente</dt>
                    <dd class="font-semibold break-words">{{ inscricao.proponente }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Categoria</dt>
                    <dd class="font-semibold break-words">{{ inscricao.categoria ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Avaliador</dt>
                    <dd class="font-semibold break-words">{{ avaliacao.avaliador }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Concluída em</dt>
                    <dd class="font-semibold tabular-nums">{{ avaliacao.concluidaEm }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Nota final</dt>
                    <dd class="text-[1.25rem] font-extrabold tabular-nums">{{ avaliacao.notaFinal ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="criterios-titulo" class="painel-retaguarda p-5 @xl:p-6">
            <h2 id="criterios-titulo" class="font-barlow text-[1.5rem] leading-none font-extrabold tracking-[0.01em] uppercase">Notas por critério</h2>
            <p class="mt-2 text-[0.9375rem] leading-[1.6] text-tinta-suave">Peso e pontuação máxima são os registrados junto com cada nota.</p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-[0.9375rem]">
                    <caption class="sr-only">
                        Notas por critério da inscrição {{ inscricao.numero }}
                    </caption>
                    <thead>
                        <tr class="border-b border-hairline text-[0.8125rem] text-tinta-suave">
                            <th scope="col" class="py-2 pr-4 font-semibold">Critério</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold">Nota</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold">Peso</th>
                            <th scope="col" class="py-2 text-right font-semibold">Pontuação máxima</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="criterio in avaliacao.criterios" :key="criterio.id">
                            <th scope="row" class="py-2.5 pr-4 font-semibold">{{ criterio.nome }}</th>
                            <td class="py-2.5 pr-4 text-right font-semibold tabular-nums">{{ criterio.nota }}</td>
                            <td class="py-2.5 pr-4 text-right tabular-nums">{{ criterio.peso ?? '—' }}</td>
                            <td class="py-2.5 text-right tabular-nums">{{ criterio.pontosMaximos ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="observacao-titulo" class="painel-retaguarda p-5 @xl:p-6">
            <h2 id="observacao-titulo" class="font-barlow text-[1.5rem] leading-none font-extrabold tracking-[0.01em] uppercase">Observação do avaliador</h2>
            <p v-if="avaliacao.observacao" class="mt-3 text-[0.9375rem] leading-[1.6] break-words whitespace-pre-line">{{ avaliacao.observacao }}</p>
            <p v-else class="mt-3 text-[0.9375rem] text-tinta-suave">O avaliador não deixou observação.</p>
        </section>
    </PaginaRetaguarda>
</template>
