<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import Interruptor from '../../Interruptor.vue';
import VinculoDaPergunta from './VinculoDaPergunta.vue';
import { classeDica } from '../listaEditavel';
import { nomeDaPergunta, perguntasEmOrdem, semVinculo } from '../questionario';
import type { CategoriaDoQuestionario, SecaoQuestionarioEditavel, VinculoEditavel } from '../../../types';

/**
 * "Vincular em lote": escolhe perguntas (uma a uma ou a seção inteira) e copia para elas o mesmo vínculo.
 * Só muda a tela; nada é gravado até salvar o questionário.
 */
const props = defineProps<{
    secoes: SecaoQuestionarioEditavel[];
    categorias: CategoriaDoQuestionario[];
    desabilitado?: boolean;
}>();

const emit = defineEmits<{
    /** Chaves das perguntas escolhidas e o vínculo a copiar para elas. */
    aplicar: [chaves: string[], vinculo: VinculoEditavel];
}>();

const dialogo = ref<HTMLDialogElement | null>(null);
const escolhidas = reactive(new Set<string>());
const vinculo = reactive<VinculoEditavel>({ todas_categorias: true, categorias: [] });
/** Remonta o VinculoDaPergunta a cada abertura (a escolha "Algumas categorias" é estado dele). */
const abertura = ref<number>(0);
const erro = ref<string>('');

const abrir = (): void => {
    escolhidas.clear();
    Object.assign(vinculo, { todas_categorias: true, categorias: [] });
    abertura.value++;
    erro.value = '';
    dialogo.value?.showModal();
};

const fechar = (): void => dialogo.value?.close();

const marcar = (chave: string, ligado: boolean): void => {
    if (ligado) escolhidas.add(chave);
    else escolhidas.delete(chave);
    erro.value = '';
};

const secaoInteira = (secao: SecaoQuestionarioEditavel): boolean => secao.perguntas.length > 0 && secao.perguntas.every((pergunta) => escolhidas.has(pergunta.chave));

const marcarSecao = (secao: SecaoQuestionarioEditavel, ligado: boolean): void => {
    for (const pergunta of secao.perguntas) marcar(pergunta.chave, ligado);
};

const semVinculoAgora = computed<string[]>(() => perguntasEmOrdem(props.secoes).filter(semVinculo).map((pergunta) => pergunta.chave));

const soAsSemVinculo = (): void => {
    escolhidas.clear();
    for (const chave of semVinculoAgora.value) escolhidas.add(chave);
    erro.value = '';
};

const aplicar = (): void => {
    const chaves = perguntasEmOrdem(props.secoes)
        .map((pergunta) => pergunta.chave)
        .filter((chave) => escolhidas.has(chave));
    if (chaves.length === 0) {
        erro.value = 'Escolha ao menos uma pergunta.';
        return;
    }

    emit('aplicar', chaves, { todas_categorias: vinculo.todas_categorias, categorias: [...vinculo.categorias] });
    fechar();
};

const rotuloDaSecao = (secao: SecaoQuestionarioEditavel, indice: number): string => `Seção ${indice + 1} · ${secao.titulo.trim() || 'Sem título'}`;

const classeBotaoSecundario =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <div class="inline-flex">
        <button
            type="button"
            :disabled="desabilitado"
            class="pressionavel inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />
            </svg>
            Vincular em lote
        </button>

        <dialog
            ref="dialogo"
            aria-labelledby="vincular-lote-titulo"
            aria-describedby="vincular-lote-descricao"
            class="dialogo-retaguarda m-auto max-h-[calc(100dvh-2rem)] w-[min(44rem,calc(100vw-2rem))] overflow-y-auto rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <div class="@container flex flex-col gap-6">
                <div>
                    <h2 id="vincular-lote-titulo" class="text-[1.25rem] leading-tight font-bold">Vincular em lote</h2>
                    <p id="vincular-lote-descricao" class="mt-2 text-[0.9375rem] text-tinta-suave">
                        Escolha as perguntas e o vínculo. O vínculo é copiado para todas elas; nada é gravado até você salvar o questionário.
                    </p>
                </div>

                <section aria-labelledby="vincular-lote-perguntas" class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 id="vincular-lote-perguntas" class="text-[1.0625rem] font-bold">Perguntas</h3>
                        <button type="button" :disabled="semVinculoAgora.length === 0" :class="[classeBotaoSecundario, 'disabled:cursor-not-allowed disabled:opacity-50']" @click="soAsSemVinculo">
                            Só as sem vínculo ({{ semVinculoAgora.length }})
                        </button>
                    </div>

                    <fieldset v-for="(secao, indice) in secoes" :key="secao.chave" class="min-w-0 rounded-[16px] border border-hairline px-4 py-3">
                        <legend class="px-1 text-[0.9375rem] font-semibold">{{ rotuloDaSecao(secao, indice) }}</legend>
                        <p v-if="secao.perguntas.length === 0" :class="[classeDica, 'py-2']">Nenhuma pergunta nesta seção.</p>
                        <template v-else>
                            <Interruptor
                                :id="`vincular-lote-secao-${secao.chave}`"
                                rotulo="Seção inteira"
                                variante="linha"
                                :model-value="secaoInteira(secao)"
                                @update:model-value="marcarSecao(secao, $event)"
                            />
                            <div class="border-l-2 border-hairline pl-4">
                                <Interruptor
                                    v-for="(pergunta, posicao) in secao.perguntas"
                                    :id="`vincular-lote-pergunta-${pergunta.chave}`"
                                    :key="pergunta.chave"
                                    :rotulo="`${posicao + 1}. ${nomeDaPergunta(pergunta)}`"
                                    :ajuda="semVinculo(pergunta) ? 'Sem vínculo' : undefined"
                                    variante="linha"
                                    :model-value="escolhidas.has(pergunta.chave)"
                                    @update:model-value="marcar(pergunta.chave, $event)"
                                />
                            </div>
                        </template>
                    </fieldset>
                    <p v-if="erro" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ erro }}</p>
                </section>

                <section aria-labelledby="vincular-lote-vinculo" class="flex flex-col gap-3">
                    <h3 id="vincular-lote-vinculo" class="text-[1.0625rem] font-bold">Vínculo</h3>
                    <VinculoDaPergunta
                        id="vincular-lote-vinculo-campos"
                        :key="abertura"
                        :model-value="vinculo"
                        :categorias="categorias"
                    />
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <p class="text-[0.9375rem] text-tinta-suave sm:mr-auto">{{ escolhidas.size }} {{ escolhidas.size === 1 ? 'pergunta escolhida' : 'perguntas escolhidas' }}</p>
                    <button type="button" :class="classeBotaoSecundario" @click="fechar">Cancelar</button>
                    <button
                        type="button"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro"
                        @click="aplicar"
                    >
                        Aplicar
                    </button>
                </div>
            </div>
        </dialog>
    </div>
</template>
