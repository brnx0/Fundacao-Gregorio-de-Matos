<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type AbaDaAvaliacao = 'avaliadores' | 'distribuicao' | 'notas';

const props = defineProps<{
    /** Slug do edital da página. */
    edital: string;
    atual: AbaDaAvaliacao;
}>();

// Caminhos das rotas retaguarda.avaliacao.avaliadores.index, retaguarda.avaliacao.distribuicao.index e
// retaguarda.avaliacao.notas.index (o front não tem helper de rotas nomeadas).
const abas: { id: AbaDaAvaliacao; rotulo: string }[] = [
    { id: 'avaliadores', rotulo: 'Avaliadores' },
    { id: 'distribuicao', rotulo: 'Distribuição' },
    { id: 'notas', rotulo: 'Notas' },
];

const caminho = (aba: AbaDaAvaliacao): string => `/retaguarda/avaliacao/editais/${props.edital}/${aba}`;
</script>

<template>
    <!-- Cada aba é uma página: navegação por links, com a página atual marcada (aria-current), e não o padrão "tabs". -->
    <nav aria-label="Etapas da avaliação" class="painel-retaguarda p-2">
        <ul class="flex gap-1 overflow-x-auto [scrollbar-width:none]">
            <li v-for="aba in abas" :key="aba.id" class="shrink-0">
                <Link
                    :href="caminho(aba.id)"
                    :aria-current="atual === aba.id ? 'page' : undefined"
                    class="inline-flex min-h-11 items-center rounded-[12px] px-5 text-[0.9375rem] font-semibold whitespace-nowrap text-home-tinta hover:bg-nevoa focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-acao aria-[current=page]:bg-acao aria-[current=page]:text-sobre-acao"
                >
                    {{ aba.rotulo }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
