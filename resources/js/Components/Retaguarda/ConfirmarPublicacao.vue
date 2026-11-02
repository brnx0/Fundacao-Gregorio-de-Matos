<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import IconeAcao from './IconeAcao.vue';

// O globo das listas da retaguarda (editais e eventos): publicar pede confirmação numa pergunta (o item passa a aparecer
// no portal para todos); ocultar a publicação continua direto. Mesmo <dialog> nativo do ConfirmarExclusao: prende o
// foco, fecha com Esc e devolve o foco ao botão que abriu.
const props = defineProps<{
    /** Endereço do PATCH que alterna a publicação. */
    url: string;
    /** Nome do item, para o botão (leitor de tela) e para a pergunta. */
    nome: string;
    publicado: boolean;
    /** O que acontece ao publicar, abaixo da pergunta. */
    aviso: string;
}>();

const dialogo = ref<HTMLDialogElement | null>(null);
const enviando = ref<boolean>(false);
const idTitulo = `confirmar-publicacao-${useId()}`;

const alternar = (): void => {
    router.patch(
        props.url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                enviando.value = true;
            },
            onFinish: () => {
                enviando.value = false;
                // Depois da tela se atualizar: ao fechar, o foco volta ao botão que abriu.
                void nextTick(fechar);
            },
        },
    );
};

const abrir = (): void => {
    if (props.publicado) {
        alternar();
        return;
    }
    dialogo.value?.showModal();
};
const fechar = (): void => dialogo.value?.close();
</script>

<template>
    <div class="inline-flex">
        <button type="button" :disabled="enviando" class="acao-icone" :data-dica="publicado ? 'Ocultar publicação' : 'Publicar'" @click="abrir">
            <IconeAcao :nome="publicado ? 'ocultar' : 'publicar'" />
            <span class="sr-only">{{ publicado ? 'Ocultar publicação de' : 'Publicar' }} {{ nome }}</span>
        </button>

        <dialog
            ref="dialogo"
            :aria-labelledby="idTitulo"
            class="dialogo-retaguarda m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <div class="flex items-start gap-4">
                <span aria-hidden="true" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-nevoa text-acao">
                    <IconeAcao nome="publicar" />
                </span>
                <div class="min-w-0">
                    <h2 :id="idTitulo" class="text-[1.25rem] leading-tight font-bold break-words">Publicar “{{ nome }}”?</h2>
                    <p class="mt-2 text-[0.9375rem] text-tinta-suave">{{ aviso }}</p>
                </div>
            </div>

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
                    :disabled="enviando"
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    @click="alternar"
                >
                    {{ enviando ? 'Publicando…' : 'Publicar' }}
                </button>
            </div>
        </dialog>
    </div>
</template>
