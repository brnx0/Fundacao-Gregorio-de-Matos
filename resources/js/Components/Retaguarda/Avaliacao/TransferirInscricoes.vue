<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import { useForm } from '@inertiajs/vue3';
import type { AvaliadorParaTransferir, OrigemDaTransferencia } from '../../../types';

const props = defineProps<{
    /** Slug do edital. */
    edital: string;
    /** Prefixo dos ids dos campos (a página pode ter mais de um formulário). */
    id: string;
    /** Para quem as inscrições podem ir: os avaliadores ativos do edital. */
    destinos: AvaliadorParaTransferir[];
    /** De quem podem sair; sem a origem fixa, escolhida na lista. */
    origens?: OrigemDaTransferencia[];
    /** Origem fixa (o atalho do aviso do desativado): só se escolhe o destino. */
    de?: OrigemDaTransferencia;
    /** Campos um embaixo do outro (célula estreita de tabela). */
    empilhado?: boolean;
}>();

// Caminho da rota retaguarda.avaliacao.transferencia; o front não tem helper de rotas nomeadas.
const URL = `/retaguarda/avaliacao/editais/${props.edital}/transferencia`;

const form = useForm<{ de: number | ''; para: number | '' }>({ de: props.de?.avaliadorId ?? '', para: '' });

const paraEscolher = computed<AvaliadorParaTransferir[]>(() => props.destinos.filter((destino) => destino.avaliadorId !== form.de));

const origem = computed<OrigemDaTransferencia | undefined>(() => props.de ?? props.origens?.find((opcao) => opcao.avaliadorId === form.de));
const destino = computed<AvaliadorParaTransferir | undefined>(() => props.destinos.find((opcao) => opcao.avaliadorId === form.para));
const quantas = computed<string>(() => {
    const total = origem.value?.naoConcluidas ?? 0;

    return total === 1 ? '1 inscrição não concluída' : `${total} inscrições não concluídas`;
});

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogo = ref<HTMLDialogElement | null>(null);
const fecharDialogo = (): void => dialogo.value?.close();

const enviar = (): void => {
    form.post(URL, {
        preserveScroll: true,
        onSuccess: () => form.reset('para'),
        onFinish: () => void nextTick(fecharDialogo),
    });
};

// Com origem, destino e inscrições a mover, confirma antes: as notas e observações lançadas nelas são apagadas.
// Sem escolha (o servidor aponta o campo) ou sem nada a mover (o servidor avisa), envia direto.
const transferir = (): void => {
    if (form.processing) return;

    if (origem.value !== undefined && destino.value !== undefined && origem.value.naoConcluidas > 0) {
        dialogo.value?.showModal();

        return;
    }

    enviar();
};

const classeCancelar =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <form novalidate class="flex flex-col gap-3" :aria-label="de ? `Transferir as inscrições não concluídas de ${de.nome}` : 'Transferir as inscrições não concluídas'" @submit.prevent="transferir">
        <div class="flex flex-col gap-3" :class="{ 'sm:flex-row sm:items-start': !empilhado }">
            <CampoCadastro v-if="!de" :id="`${id}-de`" rotulo="De" obrigatorio :erro="form.errors.de" v-slot="{ descricao, invalido }">
                <select
                    :id="`${id}-de`"
                    v-model="form.de"
                    class="campo-retaguarda"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="form.clearErrors('de', 'para')"
                >
                    <option value="" disabled>Escolha o avaliador</option>
                    <option v-for="origem in origens ?? []" :key="origem.avaliadorId" :value="origem.avaliadorId">{{ origem.nome }}</option>
                </select>
            </CampoCadastro>

            <CampoCadastro :id="`${id}-para`" :rotulo="de ? 'Transferir para' : 'Para'" obrigatorio :erro="form.errors.para" v-slot="{ descricao, invalido }">
                <select
                    :id="`${id}-para`"
                    v-model="form.para"
                    class="campo-retaguarda"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="form.clearErrors('para')"
                >
                    <option value="" disabled>Escolha o avaliador</option>
                    <option v-for="destino in paraEscolher" :key="destino.avaliadorId" :value="destino.avaliadorId">{{ destino.nome }}</option>
                </select>
            </CampoCadastro>

            <button type="submit" :disabled="form.processing" class="botao-primario shrink-0 disabled:cursor-not-allowed disabled:opacity-50" :class="{ 'sm:mt-7': !empilhado }">
                {{ form.processing ? 'Transferindo…' : 'Transferir' }}
            </button>
        </div>
        <p v-if="de && form.errors.de" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.de }}</p>
        <p v-if="destinos.length === 0" class="text-[0.8125rem] font-semibold">Não há avaliador ativo no edital para receber as inscrições.</p>
        <p class="text-[0.8125rem] leading-[1.5] text-tinta-suave">Só as não concluídas mudam de avaliador, e as notas e observações já lançadas nelas são apagadas.</p>

        <dialog
            ref="dialogo"
            :aria-labelledby="`${id}-confirmar-titulo`"
            :aria-describedby="`${id}-confirmar-aviso`"
            class="dialogo-retaguarda m-auto w-[min(30rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-left font-normal text-home-tinta backdrop:bg-noite/60"
        >
            <h2 :id="`${id}-confirmar-titulo`" class="text-[1.25rem] leading-tight font-bold">Transferir {{ quantas }} de {{ origem?.nome }} para {{ destino?.nome }}?</h2>
            <p :id="`${id}-confirmar-aviso`" class="mt-2 text-[0.9375rem] text-tinta-suave">
                As notas e observações já lançadas nelas são apagadas, e {{ destino?.nome }} começa do zero. As concluídas ficam com {{ origem?.nome }}.
            </p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" autofocus :class="classeCancelar" @click="fecharDialogo">Cancelar</button>
                <button type="button" :disabled="form.processing" class="botao-primario" @click="enviar">
                    {{ form.processing ? 'Transferindo…' : 'Transferir' }}
                </button>
            </div>
        </dialog>
    </form>
</template>
