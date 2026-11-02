<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import CartaoEdital from '../../Components/CartaoEdital.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import Paginacao from '../../Components/Retaguarda/Paginacao.vue';
import TabelaPaginada from '../../Components/Retaguarda/TabelaPaginada.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { PortalEditaisProps, SituacaoEdital, SituacaoInscricao } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

defineProps<PortalEditaisProps>();

// Caminho da rota portal.editais (o front não tem helper de rotas nomeadas).
const BASE = '/portal/editais';

type Visualizacao = 'cards' | 'lista';

// Cards por padrão; a escolha fica guardada no navegador.
const CHAVE_VISUALIZACAO = 'editaisVisualizacao';

const lerVisualizacao = (): Visualizacao => {
    try {
        return localStorage.getItem(CHAVE_VISUALIZACAO) === 'lista' ? 'lista' : 'cards';
    } catch {
        return 'cards';
    }
};

const visualizacao = ref<Visualizacao>(lerVisualizacao());

const escolherVisualizacao = (valor: Visualizacao): void => {
    visualizacao.value = valor;
    try {
        localStorage.setItem(CHAVE_VISUALIZACAO, valor);
    } catch {
        // Armazenamento indisponível (modo privado, bloqueio): vale só nesta página.
    }
};

const visualizacoes: { valor: Visualizacao; rotulo: string }[] = [
    { valor: 'cards', rotulo: 'Ver em cards' },
    { valor: 'lista', rotulo: 'Ver em lista' },
];

const corSituacao: Record<SituacaoEdital, string> = {
    aberto: 'bg-situacao-aberto',
    em_breve: 'bg-situacao-em-breve',
    encerrado: 'bg-situacao-encerrado',
};

// Selo da inscrição do próprio usuário no edital (nulo = ainda não se inscreveu).
const rotuloInscricao: Record<SituacaoInscricao, string> = {
    finalizada: 'Inscrição finalizada',
    rascunho: 'Inscrição em rascunho',
};

const corInscricao: Record<SituacaoInscricao, string> = {
    finalizada: 'bg-situacao-aberto',
    rascunho: 'bg-situacao-em-breve',
};

const classeAcao = 'pressionavel inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-nevoa';
const classeInscrever =
    'pressionavel inline-flex min-h-11 items-center rounded-pill bg-acao px-4 text-[0.8125rem] font-bold text-sobre-acao hover:bg-acao-escuro';
</script>

<template>
    <div>
        <Head title="Editais" />

        <FaixaPortal aria-labelledby="editais-titulo">
            <div class="flex flex-col gap-3">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Portal do proponente</p>
                <h1 id="editais-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Editais
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    Os editais de fomento à cultura da Fundação com inscrições abertas. Inscreva-se e baixe o PDF de cada um.
                </p>
            </div>

            <template #acoes>
                <!-- Só os ícones, sem contorno; o nome de cada botão fica para o leitor de tela e na dica do mouse. -->
                <div role="group" aria-label="Exibir editais como" class="inline-flex gap-1">
                    <button
                        v-for="opcao in visualizacoes"
                        :key="opcao.valor"
                        type="button"
                        :title="opcao.rotulo"
                        class="pressionavel inline-flex size-10 items-center justify-center rounded-full text-white/60 transition-colors hover:text-white aria-pressed:bg-white/15 aria-pressed:text-white"
                        :aria-pressed="visualizacao === opcao.valor"
                        @click="escolherVisualizacao(opcao.valor)"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <template v-if="opcao.valor === 'cards'">
                                <rect x="4" y="4" width="6.5" height="6.5" rx="1.5" />
                                <rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5" />
                                <rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5" />
                                <rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5" />
                            </template>
                            <path v-else d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01" />
                        </svg>
                        <span class="sr-only">{{ opcao.rotulo }}</span>
                    </button>
                </div>
            </template>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <div v-if="visualizacao === 'cards'" class="flex flex-col gap-6">
                <ul v-if="editais.data.length" aria-label="Editais" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="(edital, indice) in editais.data" :key="edital.slug">
                        <CartaoEdital :edital="edital" :indice="indice">
                            <p v-if="edital.minhaInscricao" class="inline-flex items-center gap-2 text-[0.8125rem] font-semibold">
                                <span aria-hidden="true" class="size-2 rounded-full" :class="corInscricao[edital.minhaInscricao]"></span>
                                {{ rotuloInscricao[edital.minhaInscricao] }}
                            </p>
                            <template #acoes>
                                <Link v-if="edital.situacao === 'aberto'" :href="`${BASE}/${edital.slug}/inscricao`" :class="classeInscrever">
                                    {{ edital.minhaInscricao ? 'Ver minha inscrição' : 'Inscrever' }}<span class="sr-only"> no edital {{ edital.titulo }}</span>
                                </Link>
                                <a :href="edital.pdf" :class="classeAcao" target="_blank" rel="noopener">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
                                    </svg>
                                    PDF<span class="sr-only"> do edital {{ edital.titulo }} (abre em nova aba)</span>
                                </a>
                            </template>
                        </CartaoEdital>
                    </li>
                </ul>
                <p v-else class="rounded-[24px] border border-hairline bg-superficie px-6 py-14 text-center text-tinta-suave">Nenhum edital com inscrições abertas no momento.</p>

                <Paginacao :links="editais.links" :total="editais.total" :de="editais.from" :ate="editais.to" />
            </div>

            <TabelaPaginada
                v-else
                legenda="Editais"
                :links="editais.links"
                :total="editais.total"
                :de="editais.from"
                :ate="editais.to"
                vazio="Nenhum edital com inscrições abertas no momento."
            >
                <template #cabecalho>
                    <tr>
                        <th scope="col" class="px-4 py-3">Título e categoria</th>
                        <th scope="col" class="px-4 py-3">Inscrições</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                    </tr>
                </template>

                <tr v-for="edital in editais.data" :key="edital.slug" class="align-middle">
                    <th scope="row" class="min-w-56 px-4 py-3 text-left font-normal">
                        <span class="block font-semibold">{{ edital.titulo }}</span>
                        <span class="block text-[0.8125rem] text-tinta-suave">{{ edital.categoria }}</span>
                    </th>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="block tabular-nums">{{ edital.periodo }}</span>
                        <span class="mt-0.5 inline-flex items-center gap-2 text-[0.8125rem] text-tinta-suave">
                            <span aria-hidden="true" class="size-2.5 rounded-full" :class="corSituacao[edital.situacao]" />
                            {{ edital.situacaoRotulo }}
                        </span>
                        <span v-if="edital.minhaInscricao" class="mt-0.5 flex items-center gap-2 text-[0.8125rem] font-semibold">
                            <span aria-hidden="true" class="size-2.5 rounded-full" :class="corInscricao[edital.minhaInscricao]" />
                            {{ rotuloInscricao[edital.minhaInscricao] }}
                        </span>
                    </td>
                    <td class="px-2 py-1">
                        <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                            <!-- Só há inscrição no período aberto (a rota recusa os demais). -->
                            <Link v-if="edital.situacao === 'aberto'" :href="`${BASE}/${edital.slug}/inscricao`" :class="classeInscrever">
                                {{ edital.minhaInscricao ? 'Ver minha inscrição' : 'Inscrever' }}<span class="sr-only"> no edital {{ edital.titulo }}</span>
                            </Link>
                            <a :href="edital.pdf" :class="classeAcao" target="_blank" rel="noopener">
                                PDF<span class="sr-only"> do edital {{ edital.titulo }} (abre em nova aba)</span>
                            </a>
                        </div>
                    </td>
                </tr>
            </TabelaPaginada>
        </div>
    </div>
</template>
