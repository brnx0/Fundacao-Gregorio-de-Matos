<script setup lang="ts">
import { nextTick, ref } from 'vue';
import type { ArquivoDaAvaliacao } from '../../types';

// O anexo aberto na própria tela, sem sair da avaliação: a imagem inteira ou o PDF num quadro (o navegador mostra o
// leitor de PDF dele). <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco a quem abriu.
// Outro tipo de arquivo, ou o celular que não mostra PDF no quadro, tem sempre o "Abrir em nova aba".
const dialogo = ref<HTMLDialogElement | null>(null);
const titulo = ref<string>('');
const arquivo = ref<ArquivoDaAvaliacao | null>(null);

const abrir = (novo: ArquivoDaAvaliacao, nomeDoDocumento: string): void => {
    arquivo.value = novo;
    titulo.value = nomeDoDocumento;
    void nextTick(() => dialogo.value?.showModal());
};

const fechar = (): void => dialogo.value?.close();

// Ao fechar, o quadro sai da página: o PDF não fica carregado (nem tocando, num vídeo) por trás.
const aoFechar = (): void => {
    arquivo.value = null;
};

// Clique no fundo escurecido (fora da caixa) também fecha.
const fecharNoFundo = (evento: MouseEvent): void => {
    if (evento.target === dialogo.value) fechar();
};

defineExpose({ abrir });
</script>

<template>
    <dialog
        ref="dialogo"
        aria-labelledby="visualizador-titulo"
        class="m-auto h-[min(92vh,60rem)] w-[min(64rem,calc(100vw-1.5rem))] max-w-none overflow-hidden rounded-card border border-hairline bg-superficie p-0 text-home-tinta backdrop:bg-noite/70"
        @close="aoFechar"
        @click="fecharNoFundo"
    >
        <div class="flex h-full flex-col">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-hairline px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 id="visualizador-titulo" class="truncate text-[1.0625rem] font-bold">{{ titulo }}</h2>
                    <p v-if="arquivo" class="truncate text-[0.8125rem] text-tinta-suave" :title="arquivo.nome">{{ arquivo.nome }}</p>
                </div>
                <a
                    v-if="arquivo"
                    :href="arquivo.url"
                    target="_blank"
                    rel="noopener"
                    class="pressionavel inline-flex min-h-11 items-center gap-2 rounded-[12px] px-3 text-[0.875rem] font-bold text-acao no-underline hover:bg-nevoa"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
                    </svg>
                    Abrir em nova aba
                </a>
                <button
                    type="button"
                    autofocus
                    class="pressionavel inline-flex size-11 items-center justify-center rounded-full text-tinta-suave hover:bg-parchment hover:text-home-tinta"
                    aria-label="Fechar"
                    @click="fechar"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 bg-parchment">
                <template v-if="arquivo">
                    <div v-if="arquivo.tipo === 'imagem'" class="flex h-full items-center justify-center p-4">
                        <img :src="arquivo.url" :alt="`${titulo}: ${arquivo.nome}`" class="max-h-full max-w-full rounded-[12px] object-contain shadow-sm" />
                    </div>
                    <iframe v-else-if="arquivo.tipo === 'pdf'" :src="arquivo.url" :title="`${titulo}: ${arquivo.nome}`" class="block size-full border-0 bg-white" />
                    <div v-else class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                        <p class="text-tinta-suave">Este tipo de arquivo não abre aqui na tela.</p>
                        <a :href="arquivo.url" target="_blank" rel="noopener" class="botao-primario">Abrir em nova aba</a>
                    </div>
                </template>
            </div>
        </div>
    </dialog>
</template>
