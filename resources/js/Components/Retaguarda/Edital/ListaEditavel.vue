<script setup lang="ts" generic="T extends ItemEditavel">
import { computed } from 'vue';
import { MAXIMO_ITENS, classeBotaoCartao, useListaEditavel } from '../listaEditavel';
import type { ItemEditavel } from '../../../types';

/**
 * Lista do edital em cartões de sanfona (chamadas, categorias e itens, documentos, critérios, grupos):
 * cabeçalho com nome e resumo, subir/descer, remover com confirmação e "Adicionar". Os campos vêm do slot.
 */
const props = withDefaults(
    defineProps<{
        /** Prefixo dos ids dos campos ("chamada" → "chamada-<chave>-nome"); único na página. */
        id: string;
        /** Prefixo das chaves de erro ("categorias.2.itens"). */
        prefixo: string;
        /** Erros do useForm. */
        erros: Partial<Record<string, string>>;
        /** Nome do item, minúsculo ("chamada", "documento"). */
        singular: string;
        plural: string;
        /** Concordância dos textos ("movida", "Recolher todas"). */
        feminino?: boolean;
        /** Texto da lista vazia. */
        vazio: string;
        /** Item novo, com chave de novaChave(). */
        novo: () => T;
        /** Campo que recebe o foco no item novo. */
        campoInicial: string;
        /** Nome mostrado no cabeçalho do cartão (vazio = "Sem nome"). */
        nome: (item: T) => string;
        /** Linha de resumo abaixo do nome, visível com o cartão recolhido. */
        resumo?: (item: T) => string;
        /** Campos com erro que contam no selo do cartão. */
        contaNoSelo?: (campo: string) => boolean;
        /** Nível do título do cartão (as listas dentro de um cartão usam 4). */
        nivel?: 3 | 4;
        /** Só leitura: sem adicionar, mover e remover; abrir e recolher os cartões continua valendo. */
        somenteLeitura?: boolean;
        /** Só os itens ainda não salvos (sem id) têm "Remover"; adicionar e mover continuam valendo. */
        removerSoNovos?: boolean;
    }>(),
    { feminino: false, resumo: undefined, contaNoSelo: undefined, nivel: 3, somenteLeitura: false, removerSoNovos: false },
);

const emit = defineEmits<{
    /** Campos de erro a limpar no useForm. */
    limparErros: [campos: string[]];
}>();

const lista = defineModel<T[]>({ required: true });

defineSlots<{
    default(escopo: {
        item: T;
        indice: number;
        idCampo: (campo: string) => string;
        erro: (campo: string) => string | undefined;
        erroDaLista: (campo: string) => string | undefined;
        limpar: (...campos: string[]) => void;
        limparLista: (campo: string) => void;
    }): unknown;
    /** Selo destacado no cabeçalho do cartão (ex.: pendência do item). */
    selo?(escopo: { item: T }): unknown;
}>();

const idCampo = (item: T, campo: string): string => `${props.id}-${item.chave}-${campo}`;

const Singular = computed<string>(() => props.singular.charAt(0).toUpperCase() + props.singular.slice(1));
const artigo = computed<string>(() => (props.feminino ? 'A' : 'O'));
const todos = computed<string>(() => (props.feminino ? 'todas' : 'todos'));

const {
    estaAberto,
    alternar,
    todosAbertos,
    alternarTodos,
    anuncio,
    botaoAdicionar,
    erro,
    quantidadeDeErros,
    erroDaLista,
    limpar,
    limparLista,
    acrescentar,
    mover,
    dialogo,
    aRemover,
    pedirRemocao,
    cancelarRemocao,
    remover,
} = useListaEditavel<T>({
    lista,
    prefixo: () => props.prefixo,
    erros: () => props.erros,
    limparErros: (campos) => emit('limparErros', campos),
    idCampo,
    textoMovido: (posicao, total) => `${Singular.value} ${props.feminino ? 'movida' : 'movido'} para a posição ${posicao} de ${total}.`,
    textoRemovido: () => `${Singular.value} ${props.feminino ? 'removida' : 'removido'}.`,
    contaNoSelo: props.contaNoSelo,
});

const nomeNaPosicao = (indice: number): string => {
    const item = lista.value[indice];

    return (item && props.nome(item).trim()) || `${Singular.value} ${indice + 1}`;
};

const idTituloDialogo = computed<string>(() => `${props.id}-remover-titulo`);
const tituloDoCartao = computed<string>(() => `h${props.nivel}`);

/** Item já salvo (com id) num edital que só deixa remover o que é novo. */
const removeBloqueado = (item: T): boolean => props.removerSoNovos && (item as { id?: number | null }).id != null;
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="erros[prefixo]" role="alert" class="text-[0.9375rem] font-semibold text-invalido-texto">{{ erros[prefixo] }}</p>

        <p v-if="lista.length === 0" class="rounded-[16px] border border-dashed border-tinta-suave/50 p-5 text-[0.9375rem] leading-[1.6] text-tinta-suave">
            {{ vazio }}
        </p>

        <div v-if="lista.length >= 2" class="-mb-2">
            <button type="button" class="pressionavel inline-flex min-h-11 items-center rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-nevoa" @click="alternarTodos">
                {{ todosAbertos() ? `Recolher ${todos}` : `Expandir ${todos}` }}<span class="sr-only">{{ ` ${todos === 'todas' ? 'as' : 'os'} ${plural}` }}</span>
            </button>
        </div>

        <ol v-if="lista.length > 0" class="flex flex-col gap-4">
            <li v-for="(item, indice) in lista" :key="item.chave">
                <article
                    :id="idCampo(item, 'cartao')"
                    tabindex="-1"
                    :aria-labelledby="idCampo(item, 'titulo')"
                    class="rounded-[20px] border border-hairline p-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-acao md:p-5"
                >
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <component :is="tituloDoCartao" :id="idCampo(item, 'titulo')" class="min-w-0 flex-1 basis-60 text-[1.0625rem] font-bold">
                            <button
                                :id="idCampo(item, 'alternar')"
                                type="button"
                                :aria-expanded="estaAberto(item)"
                                :aria-controls="idCampo(item, 'corpo')"
                                class="pressionavel flex min-h-11 w-full items-center gap-3 rounded-[12px] py-1 text-left"
                                @click="alternar(item)"
                            >
                                <svg
                                    aria-hidden="true"
                                    viewBox="0 0 24 24"
                                    class="size-5 shrink-0 text-acao transition-transform duration-200 motion-reduce:transition-none"
                                    :class="estaAberto(item) ? 'rotate-180' : ''"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="M6 9l6 6 6-6" />
                                </svg>
                                <span class="flex min-w-0 flex-col">
                                    <span class="truncate">{{ Singular }} {{ indice + 1 }}<span class="font-semibold"> · {{ nome(item).trim() || 'Sem nome' }}</span></span>
                                    <span v-if="resumo" class="truncate text-[0.875rem] font-normal text-tinta-suave tabular-nums">{{ resumo(item) }}</span>
                                </span>
                                <slot name="selo" :item="item" />
                                <span v-if="quantidadeDeErros(indice) > 0" class="ml-auto shrink-0 rounded-pill bg-erro-fundo px-2.5 py-0.5 text-[0.8125rem] font-bold text-erro-texto">
                                    {{ quantidadeDeErros(indice) }}<span class="sr-only">{{ ` ${quantidadeDeErros(indice) === 1 ? 'erro' : 'erros'} ${feminino ? 'nesta' : 'neste'} ${singular}` }}</span>
                                </span>
                            </button>
                        </component>

                        <div v-if="!somenteLeitura" class="-mx-3 flex flex-wrap items-center">
                            <button :id="idCampo(item, 'subir')" type="button" :disabled="indice === 0" :class="classeBotaoCartao" @click="mover(indice, -1, 'subir')">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 19V5M6 11l6-6 6 6" />
                                </svg>
                                Subir<span class="sr-only">{{ ` ${singular} ${indice + 1}` }}</span>
                            </button>
                            <button :id="idCampo(item, 'descer')" type="button" :disabled="indice === lista.length - 1" :class="classeBotaoCartao" @click="mover(indice, 1, 'descer')">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14M6 13l6 6 6-6" />
                                </svg>
                                Descer<span class="sr-only">{{ ` ${singular} ${indice + 1}` }}</span>
                            </button>
                            <button
                                v-if="!removeBloqueado(item)"
                                type="button"
                                class="pressionavel inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-[0.8125rem] font-bold text-erro-texto hover:bg-erro-fundo"
                                @click="pedirRemocao(indice)"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                                </svg>
                                Remover<span class="sr-only">{{ ` ${singular} ${indice + 1}` }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Consultas de contêiner: a largura do cartão depende das abas laterais, não só da tela. -->
                    <div :id="idCampo(item, 'corpo')" v-show="estaAberto(item)" class="@container mt-4 grid gap-x-6 gap-y-5">
                        <slot
                            :item="item"
                            :indice="indice"
                            :id-campo="(campo: string) => idCampo(item, campo)"
                            :erro="(campo: string) => erro(indice, campo)"
                            :erro-da-lista="(campo: string) => erroDaLista(indice, campo)"
                            :limpar="(...campos: string[]) => limpar(indice, ...campos)"
                            :limpar-lista="(campo: string) => limparLista(indice, campo)"
                        />
                    </div>
                </article>
            </li>
        </ol>

        <div v-if="!somenteLeitura" class="flex flex-col items-start gap-2">
            <button
                ref="botaoAdicionar"
                type="button"
                :disabled="lista.length >= MAXIMO_ITENS"
                class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
                @click="acrescentar(novo(), campoInicial)"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Adicionar {{ singular }}
            </button>
            <p v-if="lista.length >= MAXIMO_ITENS" class="text-[0.8125rem] text-tinta-suave">Cadastre no máximo {{ MAXIMO_ITENS }} {{ plural }}.</p>
        </div>

        <p class="sr-only" aria-live="polite">{{ anuncio }}</p>

        <dialog
            ref="dialogo"
            :aria-labelledby="idTituloDialogo"
            class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
            @cancel="aRemover = null"
        >
            <h2 :id="idTituloDialogo" class="text-[1.25rem] leading-tight font-bold">Remover “{{ aRemover === null ? '' : nomeNaPosicao(aRemover) }}”?</h2>
            <p class="mt-2 text-[0.9375rem] text-tinta-suave">{{ artigo }} {{ singular }} sai da lista; a remoção só vale depois de salvar o edital.</p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    autofocus
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                    @click="cancelarRemocao"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-erro-texto bg-erro-fundo px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-erro-texto uppercase"
                    @click="remover"
                >
                    Remover
                </button>
            </div>
        </dialog>
    </div>
</template>
