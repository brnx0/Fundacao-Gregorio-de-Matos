<script setup lang="ts">
import { computed, ref } from 'vue';
import AbasDaAvaliacao from '../../../Components/Retaguarda/Avaliacao/AbasDaAvaliacao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { classeCaixa, classeMarcacao } from '../../../Components/Retaguarda/listaEditavel';
import { Head, Link, router } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { ModoDistribuicao, RetaguardaAvaliacaoDistribuicaoProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliacaoDistribuicaoProps>();

// Caminhos das rotas retaguarda.avaliacao.index e retaguarda.avaliacao.distribuicao.index / .store (a mesma URL, GET
// e POST); o front não tem helper de rotas nomeadas.
const LISTA = '/retaguarda/avaliacao';
const BASE = `${LISTA}/editais/${props.edital.slug}/distribuicao`;

const descricoes: Record<ModoDistribuicao, string> = {
    igual: 'Cada inscrição vai para o avaliador do edital com menos inscrições; o empate é sorteado.',
    por_categoria: 'Cada inscrição vai para o avaliador da categoria dela com menos inscrições. Categoria sem avaliador fica de fora.',
};

// O modo escolhido vem da URL (?modo=), para a estimativa ser refeita ao voltar para a página.
const modo = ref<ModoDistribuicao | null>(props.previa?.modo ?? null);
const carregando = ref<boolean>(false);
const distribuindo = ref<boolean>(false);

const estimar = (escolhido: ModoDistribuicao): void => {
    modo.value = escolhido;
    router.get(
        BASE,
        { modo: escolhido },
        {
            only: ['previa'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                carregando.value = true;
            },
            onFinish: () => {
                carregando.value = false;
            },
        },
    );
};

const previaAtual = computed(() => (props.previa !== null && props.previa.modo === modo.value ? props.previa : null));
const totalNaPrevia = computed<number>(() => previaAtual.value?.porAvaliador.reduce((soma, linha) => soma + linha.quantidade, 0) ?? 0);
const podeDistribuir = computed<boolean>(() => props.pendencias.length === 0 && totalNaPrevia.value > 0 && !carregando.value && !distribuindo.value);

const distribuir = (): void => {
    if (!podeDistribuir.value || modo.value === null) return;

    router.post(
        BASE,
        { modo: modo.value },
        {
            preserveScroll: true,
            onStart: () => {
                distribuindo.value = true;
            },
            onFinish: () => {
                distribuindo.value = false;
            },
        },
    );
};

const plural = (quantidade: number, singular: string, varios: string): string => `${quantidade} ${quantidade === 1 ? singular : varios}`;
</script>

<template>
    <PaginaRetaguarda
        :titulo="edital.titulo"
        descricao="Reparta as inscrições homologadas sem avaliador entre os avaliadores do edital. As que já têm avaliador ficam como estão."
        :trilha="[{ texto: edital.titulo }]"
    >
        <Head :title="`Distribuição · ${edital.titulo} · Avaliação · Retaguarda`" />

        <template #detalhe>
            <span v-for="(periodo, indice) in edital.periodos" :key="indice" class="selo-faixa">Avaliação: {{ periodo }}</span>
        </template>

        <template #acoes>
            <Link
                :href="LISTA"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M11 18l-6-6 6-6" />
                </svg>
                Voltar para os editais
            </Link>
        </template>

        <AbasDaAvaliacao :edital="edital.slug" atual="distribuicao" />

        <div v-if="pendencias.length > 0" role="note" aria-labelledby="pendencias-titulo" class="rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
            <h2 id="pendencias-titulo" class="text-[0.9375rem] font-bold">Antes de distribuir as inscrições</h2>
            <ul class="mt-2 flex list-disc flex-col gap-1 pl-5 text-[0.9375rem] leading-[1.5]">
                <li v-for="pendencia in pendencias" :key="pendencia">{{ pendencia }}</li>
            </ul>
        </div>

        <section aria-labelledby="distribuir-titulo" class="painel-retaguarda p-4 @3xl:p-6">
            <h2 id="distribuir-titulo" class="font-barlow text-[1.5rem] leading-none font-extrabold tracking-[0.01em] uppercase">Distribuir</h2>
            <p class="mt-2 text-[0.9375rem] leading-[1.6] text-tinta-suave">
                Escolha o modo para ver a estimativa. Ao confirmar, a conta é refeita na hora (pode ter entrado inscrição nova) e a mensagem mostra o que foi gravado.
            </p>

            <fieldset class="mt-5 min-w-0">
                <legend class="text-[0.9375rem] font-semibold">Como distribuir</legend>
                <div class="mt-1.5 flex flex-col">
                    <label v-for="opcao in modos" :key="opcao.valor" :class="classeMarcacao">
                        <input
                            type="radio"
                            name="modo"
                            :value="opcao.valor"
                            :checked="modo === opcao.valor"
                            :disabled="distribuindo"
                            :class="classeCaixa"
                            :aria-describedby="`modo-${opcao.valor}-ajuda`"
                            @change="estimar(opcao.valor)"
                        />
                        <span>
                            <span class="block font-semibold">{{ opcao.rotulo }}</span>
                            <span :id="`modo-${opcao.valor}-ajuda`" class="block text-[0.8125rem] text-tinta-suave">{{ descricoes[opcao.valor] }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div aria-live="polite" class="mt-5">
                <p v-if="carregando" class="text-[0.9375rem] text-tinta-suave">Calculando a estimativa…</p>
                <template v-else-if="previaAtual">
                    <p v-if="totalNaPrevia === 0 && previaAtual.semAvaliador.length === 0" class="font-semibold text-home-tinta">Não há inscrições homologadas sem avaliador.</p>
                    <template v-else>
                        <h3 class="text-[0.9375rem] font-bold">Estimativa: {{ plural(totalNaPrevia, 'inscrição', 'inscrições') }}</h3>
                        <ul v-if="previaAtual.porAvaliador.length > 0" class="mt-2 flex flex-col divide-y divide-hairline rounded-[12px] border border-hairline">
                            <li v-for="linha in previaAtual.porAvaliador" :key="linha.nome" class="flex items-center justify-between gap-4 px-4 py-2.5 text-[0.9375rem]">
                                <span class="font-semibold">{{ linha.nome }}</span>
                                <span class="tabular-nums">{{ plural(linha.quantidade, 'inscrição', 'inscrições') }}</span>
                            </li>
                        </ul>
                        <div
                            v-if="previaAtual.semAvaliador.length > 0"
                            role="note"
                            class="mt-3 rounded-[12px] border border-aviso-texto/30 bg-aviso-fundo px-4 py-3 text-[0.9375rem] leading-[1.5] text-aviso-texto"
                        >
                            <p class="font-semibold">Ficariam sem avaliador:</p>
                            <ul class="mt-1 list-disc pl-5">
                                <li v-for="sobra in previaAtual.semAvaliador" :key="sobra.categoria">{{ sobra.categoria }}: {{ sobra.quantidade }}</li>
                            </ul>
                            <p class="mt-1">Inclua um avaliador para essas categorias na aba Avaliadores.</p>
                        </div>
                    </template>
                </template>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <button type="button" :disabled="!podeDistribuir" class="botao-primario disabled:cursor-not-allowed disabled:opacity-50" @click="distribuir">
                    {{ distribuindo ? 'Distribuindo…' : 'Distribuir as inscrições' }}
                </button>
            </div>
        </section>

        <PainelLista
            legenda="Inscrições homologadas do edital"
            :links="inscricoes.links"
            :total="inscricoes.total"
            :de="inscricoes.from"
            :ate="inscricoes.to"
            vazio="Nenhuma inscrição homologada neste edital."
        >
            <template #cabecalho>
                <tr>
                    <th scope="col">Inscrição</th>
                    <th scope="col">Proponente</th>
                    <th scope="col">Categoria</th>
                    <th scope="col">Avaliador</th>
                    <th scope="col">Situação</th>
                </tr>
            </template>

            <tr v-for="inscricao in inscricoes.data" :key="inscricao.id" class="align-middle">
                <th scope="row" class="text-left font-semibold whitespace-nowrap tabular-nums">{{ inscricao.numero }}</th>
                <td class="min-w-56 font-semibold">{{ inscricao.proponente }}</td>
                <td class="min-w-40">{{ inscricao.categoria ?? '—' }}</td>
                <td class="min-w-40">{{ inscricao.avaliador ?? '—' }}</td>
                <td>
                    <span class="selo" :class="{ 'selo-atencao': inscricao.avaliador === null, 'selo-positivo': inscricao.situacaoRotulo === 'Concluída' }">
                        {{ inscricao.situacaoRotulo }}
                    </span>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
