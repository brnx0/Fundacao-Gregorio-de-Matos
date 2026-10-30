<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CampoCadastro from '../../CampoCadastro.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';
import type { MarcacaoConferida } from '../../../types';

const props = defineProps<{
    inscricaoId: number;
    tipo: 'documento' | 'pergunta';
    /** Id do documento exigido ou da pergunta. */
    item: number;
    marcacao: MarcacaoConferida | null;
    /** Só consulta (com decisão, fora do período ou sem ele): mostra a marcação sem os botões. */
    desabilitado: boolean;
}>();

const LIMITE_OBSERVACAO = 1000;

const id = `marcacao-${useId()}`;
const idFormulario = `${id}-formulario`;
const idObservacao = `${id}-observacao`;

// useForm por item: o erro de validação ("Descreva o problema.") fica só no item que foi salvo, não em todos da tela.
const form = useForm<{ tipo: 'documento' | 'pergunta'; item: number; situacao: 'ok' | 'problema'; observacao: string }>({
    tipo: props.tipo,
    item: props.item,
    situacao: 'ok',
    observacao: '',
});

// A observação abre ao escolher "Com problema" (o foco vai para ela) e fecha ao salvar ou cancelar. Os botões dizem a
// marcação gravada (aria-pressed): três estados, não conferido, ok e com problema.
const observacaoAberta = ref<boolean>(false);
const botaoProblema = ref<HTMLButtonElement | null>(null);

// Caminho da rota retaguarda.homologacao.conferencias.update (o front não tem helper de rotas nomeadas).
const enviar = (situacao: 'ok' | 'problema'): void => {
    form.situacao = situacao;
    form.transform((dados) => (situacao === 'ok' ? { tipo: dados.tipo, item: dados.item, situacao } : { ...dados, observacao: dados.observacao.trim() })).put(
        `/retaguarda/homologacao/${props.inscricaoId}/conferencias`,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (situacao === 'problema' && !form.hasErrors) {
                    observacaoAberta.value = false;
                }
            },
            onError: () => focarPrimeiroErro(`#${idFormulario}`),
        },
    );
};

const marcarOk = (): void => {
    observacaoAberta.value = false;
    form.clearErrors();
    if (props.marcacao?.situacao !== 'ok') {
        enviar('ok');
    }
};

const abrirObservacao = (): void => {
    form.clearErrors();
    form.observacao = props.marcacao?.situacao === 'problema' ? (props.marcacao.observacao ?? '') : '';
    observacaoAberta.value = true;
    void nextTick(() => document.getElementById(idObservacao)?.focus());
};

// O campo some: o foco volta ao botão que o abriu.
const cancelar = (): void => {
    observacaoAberta.value = false;
    form.clearErrors();
    botaoProblema.value?.focus();
};

// Texto só com espaços conta como vazio, como no servidor.
const salvarProblema = (): void => {
    if (form.observacao.trim() === '') {
        form.setError('observacao', 'Descreva o problema.');
        focarPrimeiroErro(`#${idFormulario}`);
        return;
    }
    enviar('problema');
};

// Pressionado: texto verde sobre o verde claro, que se ajusta no modo escuro; no alto contraste (o verde não lê no
// preto), o amarelo de ação.
const classeBotao =
    'pressionavel inline-flex min-h-10 items-center gap-1.5 rounded-pill border px-4 text-[0.8125rem] font-bold disabled:cursor-wait disabled:opacity-70';
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="!desabilitado" role="group" :aria-labelledby="`${id}-rotulo`" class="flex flex-wrap items-center gap-2">
            <span :id="`${id}-rotulo`" class="sr-only">Conferência</span>
            <button
                type="button"
                :aria-pressed="marcacao?.situacao === 'ok' ? 'true' : 'false'"
                :disabled="form.processing"
                :class="[
                    classeBotao,
                    marcacao?.situacao === 'ok'
                        ? 'border-texto-verde bg-texto-verde/10 text-texto-verde [html.alto-contraste_&]:border-acao [html.alto-contraste_&]:bg-superficie [html.alto-contraste_&]:text-acao'
                        : 'border-tinta-suave/60 bg-superficie text-home-tinta hover:border-texto-verde hover:text-texto-verde',
                ]"
                @click="marcarOk"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12 5 5 9-10" />
                </svg>
                Ok
            </button>
            <button
                ref="botaoProblema"
                type="button"
                :aria-pressed="marcacao?.situacao === 'problema' ? 'true' : 'false'"
                :disabled="form.processing"
                :class="[
                    classeBotao,
                    marcacao?.situacao === 'problema'
                        ? 'border-erro-texto bg-erro-fundo text-erro-texto'
                        : 'border-tinta-suave/60 bg-superficie text-home-tinta hover:border-erro-texto hover:text-erro-texto',
                ]"
                @click="abrirObservacao"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v5M12 16h.01" />
                </svg>
                Com problema
            </button>
            <span v-if="!marcacao" class="text-[0.8125rem] text-tinta-suave">Não conferido</span>
        </div>

        <form v-show="observacaoAberta && !desabilitado" :id="idFormulario" novalidate class="flex flex-col gap-3" @submit.prevent="salvarProblema">
            <CampoCadastro :id="idObservacao" rotulo="O que está com problema" obrigatorio :erro="form.errors.observacao" v-slot="{ descricao, invalido }">
                <textarea
                    :id="idObservacao"
                    v-model="form.observacao"
                    rows="3"
                    :maxlength="LIMITE_OBSERVACAO"
                    required
                    class="campo-retaguarda h-auto! py-3 leading-[1.6]"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @input="form.clearErrors('observacao')"
                />
            </CampoCadastro>
            <div class="flex flex-wrap gap-2">
                <button type="submit" :disabled="form.processing" class="botao-primario">{{ form.processing ? 'Salvando…' : 'Salvar' }}</button>
                <button type="button" class="botao-secundario" @click="cancelar">Cancelar</button>
            </div>
        </form>

        <p v-if="form.errors.item" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.item }}</p>

        <div v-if="marcacao && (desabilitado || !observacaoAberta)" class="text-[0.9375rem]">
            <p v-if="desabilitado" class="font-semibold" :class="marcacao.situacao === 'ok' ? 'text-texto-verde [html.alto-contraste_&]:text-acao' : 'text-erro-texto'">
                {{ marcacao.situacao === 'ok' ? 'Ok' : 'Com problema' }}
            </p>
            <p v-if="marcacao.situacao === 'problema' && marcacao.observacao" class="break-words whitespace-pre-line">{{ marcacao.observacao }}</p>
            <p class="text-[0.8125rem] text-tinta-suave">
                <template v-if="marcacao.automatica">Marcado automaticamente: obrigatório não enviado.</template>
                <template v-else-if="marcacao.por">Por {{ marcacao.por }}<template v-if="marcacao.em"> em {{ marcacao.em }}</template></template>
            </p>
        </div>
        <p v-else-if="!marcacao && desabilitado" class="text-[0.9375rem] text-tinta-suave">Não conferido</p>
    </div>
</template>
