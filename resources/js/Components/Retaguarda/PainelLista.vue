<script setup lang="ts">
import Paginacao from './Paginacao.vue';
import type { LinkPaginacao } from '../../types';

defineProps<{
    /** Título da tabela para o leitor de tela. */
    legenda: string;
    links: LinkPaginacao[];
    total: number;
    de: number | null;
    ate: number | null;
    /** Texto quando a lista (já filtrada) está vazia. */
    vazio: string;
}>();
</script>

<template>
    <!-- Lista da retaguarda num painel só: filtros no alto (slot "filtros"), a tabela e a paginação no pé.
         A TabelaPaginada continua para o portal; esta é a versão da retaguarda. -->
    <section :aria-label="legenda" class="painel-retaguarda overflow-hidden">
        <div v-if="$slots.filtros" class="border-b border-hairline bg-superficie p-4 @3xl:p-5">
            <slot name="filtros" />
        </div>

        <!-- relative: os textos sr-only (absolutos) ficam presos à rolagem e não alargam a página. -->
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[50rem] border-collapse text-left text-[0.9375rem]">
                <caption class="sr-only">{{ legenda }}</caption>
                <thead class="bg-parchment text-[0.6875rem] font-bold tracking-[0.12em] text-tinta-suave uppercase [&_th]:px-5 [&_th]:py-3">
                    <slot name="cabecalho" />
                </thead>
                <tbody class="divide-y divide-hairline [&>tr]:transition-colors [&>tr:hover]:bg-nevoa/60 [&_td]:px-5 [&_td]:py-3.5 [&_th]:px-5 [&_th]:py-3.5">
                    <slot />
                    <tr v-if="total === 0">
                        <td colspan="100" class="py-16! text-center">
                            <span aria-hidden="true" class="mx-auto mb-3 flex size-14 items-center justify-center rounded-full bg-nevoa text-acao">
                                <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="7" />
                                    <path d="m20 20-3.5-3.5M8.5 11h5" />
                                </svg>
                            </span>
                            <span class="font-semibold text-home-tinta">{{ vazio }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="total > 0" class="border-t border-hairline px-4 py-3 @3xl:px-5">
            <Paginacao :links="links" :total="total" :de="de" :ate="ate" />
        </div>
    </section>
</template>
