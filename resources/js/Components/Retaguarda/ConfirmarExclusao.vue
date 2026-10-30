<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';

const props = withDefaults(defineProps<{
    /** Endereço do DELETE. */
    url: string;
    /** Nome do item, para o botão (leitor de tela) e para a pergunta. */
    nome: string;
    /** O que acontece ao excluir, abaixo da pergunta. */
    aviso?: string;
    /** Verbo do botão e da confirmação ("Desvincular", "Sair da organização"); o padrão é "Excluir". */
    acao?: string;
    /** Pergunta do diálogo; o padrão é `Excluir “nome”?`. */
    pergunta?: string;
    /** Texto do botão de confirmar enquanto envia; o padrão é "Excluindo…". */
    acaoEmAndamento?: string;
    /** Mantém a rolagem após excluir; desligue quando a mensagem de sucesso fica no topo da página. */
    manterRolagem?: boolean;
    /** Trava o botão que abre a pergunta (por exemplo, enquanto outra requisição da tela está em andamento). */
    desabilitado?: boolean;
    /** Botão só com a lixeira e a dica (listas da retaguarda); o padrão mostra o ícone e o texto. */
    icone?: boolean;
}>(), { manterRolagem: true, desabilitado: false, icone: false });

const dialogo = ref<HTMLDialogElement | null>(null);
const excluindo = ref<boolean>(false);
const idTitulo = `confirmar-exclusao-${useId()}`;

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const abrir = (): void => dialogo.value?.showModal();
const fechar = (): void => dialogo.value?.close();

const excluir = (): void => {
    router.delete(props.url, {
        preserveScroll: props.manterRolagem,
        onStart: () => {
            excluindo.value = true;
        },
        onFinish: () => {
            excluindo.value = false;
            // Depois da tela se atualizar: ao fechar, o foco volta ao botão que abriu, que só destrava quando a requisição termina.
            void nextTick(fechar);
        },
    });
};
</script>

<template>
    <div class="inline-flex">
        <button
            v-if="icone"
            type="button"
            :disabled="desabilitado"
            class="acao-icone acao-icone-perigo"
            :data-dica="acao ?? 'Excluir'"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
            </svg>
            <span class="sr-only">{{ acao ?? 'Excluir' }} {{ nome }}</span>
        </button>
        <button
            v-else
            type="button"
            :disabled="desabilitado"
            class="pressionavel inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-[0.8125rem] font-bold text-erro-texto hover:bg-erro-fundo disabled:cursor-not-allowed disabled:opacity-70"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
            </svg>
            {{ acao ?? 'Excluir' }}<span class="sr-only"> {{ nome }}</span>
        </button>

        <dialog
            ref="dialogo"
            :aria-labelledby="idTitulo"
            class="dialogo-retaguarda m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <h2 :id="idTitulo" class="text-[1.25rem] leading-tight font-bold">{{ pergunta ?? `Excluir “${nome}”?` }}</h2>
            <p v-if="aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">{{ aviso }}</p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    autofocus
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                    @click="fechar"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="excluindo"
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-erro-texto bg-erro-fundo px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-erro-texto uppercase disabled:cursor-wait disabled:opacity-70"
                    @click="excluir"
                >
                    {{ excluindo ? (acaoEmAndamento ?? 'Excluindo…') : (acao ?? 'Excluir') }}
                </button>
            </div>
        </dialog>
    </div>
</template>
