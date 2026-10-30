<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../CampoArquivo.vue';
import CampoCadastro from '../../CampoCadastro.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';

const props = defineProps<{
    /** Tamanho máximo do PDF, fixo no código (14 MB). */
    tamanhoMaximoMb: number;
    /** Há provedor principal com modelo e chave; sem ele, a janela avisa que o serviço está indisponível em vez de pedir o PDF. */
    configurada: boolean;
}>();

const URL_IMPORTACOES = '/retaguarda/editais/importacoes';
const FORMULARIO = '#formulario-importar-edital';
// Mesmo texto da recusa do servidor (AnaliseComRedundancia::INDISPONIVEL).
const SEM_CONFIGURACAO = 'Serviço de IA indisponível no momento. Caso persista, contate o administrador.';

const dialogo = ref<HTMLDialogElement | null>(null);
const form = useForm({ pdf: null as File | null });

// Recusa do servidor quando a configuração saiu com a página aberta (a lista volta já sem ela).
const recusa = ref<string>('');
const semConfiguracao = computed<boolean>(() => !props.configurada || recusa.value !== '');

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const abrir = (): void => dialogo.value?.showModal();
const fechar = (): void => dialogo.value?.close();

// Ao fechar (Cancelar, Esc ou depois de enviar), a escolha some, inclusive a recusada que ficou no campo nativo.
const aoFechar = (): void => {
    recusa.value = '';
    form.reset();
    form.clearErrors();
    const entrada = dialogo.value?.querySelector<HTMLInputElement>('input[type="file"]');
    if (entrada) entrada.value = '';
};

// O PDF grande nem sai do navegador; o servidor confere de novo.
const escolher = (arquivo: File | null): void => {
    form.clearErrors('pdf');

    if (arquivo && arquivo.size > props.tamanhoMaximoMb * 1024 * 1024) {
        form.pdf = null;
        form.setError('pdf', `O PDF deve ter no máximo ${props.tamanhoMaximoMb} MB.`);
        return;
    }
    form.pdf = arquivo;
};

const enviar = (): void => {
    if (!form.pdf) {
        form.setError('pdf', 'Este campo é obrigatório.');
        focarPrimeiroErro(FORMULARIO);
        return;
    }

    form.post(URL_IMPORTACOES, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => fechar(),
        onError: (erros) => {
            recusa.value = erros.configuracao ?? '';
            focarPrimeiroErro(FORMULARIO);
        },
    });
};

const textoBotao = computed(() => {
    if (!form.processing) return 'Importar';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Enviando…';
});
</script>

<template>
    <div class="inline-flex">
        <button
            type="button"
            class="vidro vidro-pilula pressionavel inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-pill px-6 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.94 15.5a2 2 0 0 0-1.44-1.44l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0l1.58 6.13a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0zM20 3v4M22 5h-4M4 17v2M5 18H3" />
            </svg>
            Importar com IA
        </button>

        <dialog
            ref="dialogo"
            aria-labelledby="importar-edital-titulo"
            :aria-describedby="semConfiguracao ? 'importar-edital-descricao importar-edital-recusa' : 'importar-edital-descricao'"
            class="dialogo-retaguarda m-auto w-[min(32rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
            @close="aoFechar"
        >
            <form id="formulario-importar-edital" novalidate class="flex flex-col gap-5" @submit.prevent="enviar">
                <div>
                    <h2 id="importar-edital-titulo" class="text-[1.25rem] leading-tight font-bold">Importar edital com IA</h2>
                    <p id="importar-edital-descricao" class="mt-2 text-[0.9375rem] text-tinta-suave">
                        A IA lê o PDF e cria um edital novo, não publicado, para você conferir. A análise roda em segundo plano e pode levar alguns
                        minutos; você pode continuar usando o sistema.
                    </p>
                </div>

                <AvisoCamposObrigatorios v-if="!semConfiguracao" />

                <div v-if="semConfiguracao" id="importar-edital-recusa" role="alert" class="flex items-start gap-2 rounded-[12px] bg-nevoa px-4 py-3 font-semibold text-acao">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 8h.01M11 12h1v5h1" />
                    </svg>
                    <p>{{ recusa || SEM_CONFIGURACAO }}</p>
                </div>

                <CampoCadastro v-else id="importar-pdf" rotulo="PDF do edital" obrigatorio :erro="form.errors.pdf" v-slot="{ descricao, invalido }">
                    <CampoArquivo
                        id="importar-pdf"
                        accept="application/pdf"
                        :dica="`Só PDF, até ${tamanhoMaximoMb} MB`"
                        :arquivo="form.pdf"
                        obrigatorio
                        :descricao="descricao"
                        :invalido="invalido"
                        @escolher="escolher"
                    />
                </CampoCadastro>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                        @click="fechar"
                    >
                        {{ semConfiguracao ? 'Fechar' : 'Cancelar' }}
                    </button>
                    <button
                        v-if="!semConfiguracao"
                        type="submit"
                        :disabled="form.processing"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ textoBotao }}
                    </button>
                </div>
            </form>
        </dialog>
    </div>
</template>
