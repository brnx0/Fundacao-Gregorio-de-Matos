<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CampoCadastro from '../../CampoCadastro.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';

const props = defineProps<{
    inscricaoId: number;
    /** Sem decisão e dentro do período de homologação. */
    podeConferir: boolean;
    /** Pode conferir e todos os itens estão ok. */
    podeHomologar: boolean;
    /** Motivo montado dos itens com problema; vazio sem problema. */
    motivoSugerido: string;
}>();

const LIMITE_MOTIVO = 4000;
const FORMULARIO = '#formulario-nao-homologar';

const form = useForm<{ decisao: 'homologada' | 'nao_homologada'; motivo: string }>({ decisao: 'homologada', motivo: '' });

// Sem item com problema, o motivo escrito é o que justifica a não homologação; com problema, o servidor usa o montado.
const motivoObrigatorio = computed<boolean>(() => props.motivoSugerido === '');

const porQueNaoHomologa = computed<string>(() =>
    props.podeConferir ? 'Para homologar, marque todos os itens como ok.' : 'A inscrição está só para consulta.',
);

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogoHomologar = ref<HTMLDialogElement | null>(null);
const dialogoNaoHomologar = ref<HTMLDialogElement | null>(null);

const abrirHomologar = (): void => dialogoHomologar.value?.showModal();

// O motivo vem montado dos itens com problema a cada abertura, como está agora nas marcações.
const abrirNaoHomologar = (): void => {
    form.clearErrors();
    form.motivo = props.motivoSugerido;
    dialogoNaoHomologar.value?.showModal();
};

const fechar = (): void => {
    dialogoHomologar.value?.close();
    dialogoNaoHomologar.value?.close();
};

// Caminho da rota retaguarda.homologacao.decisao (o front não tem helper de rotas nomeadas).
const decidir = (decisao: 'homologada' | 'nao_homologada'): void => {
    form.decisao = decisao;
    form.transform((dados) => (decisao === 'homologada' ? { decisao } : { decisao, motivo: dados.motivo.trim() })).post(
        `/retaguarda/homologacao/${props.inscricaoId}/decisao`,
        {
            preserveScroll: true,
            // Decidida, esta parte sai da tela e o resultado entra: o foco vai para o conteúdo, onde a mensagem aparece.
            onSuccess: () => {
                fechar();
                void nextTick(() => document.getElementById('conteudo')?.focus());
            },
            onError: () => focarPrimeiroErro(FORMULARIO),
        },
    );
};

// Texto só com espaços conta como vazio, como no servidor.
const naoHomologar = (): void => {
    if (motivoObrigatorio.value && form.motivo.trim() === '') {
        form.setError('motivo', 'Escreva o motivo.');
        focarPrimeiroErro(FORMULARIO);
        return;
    }
    decidir('nao_homologada');
};

const classeDialogo = 'dialogo-retaguarda m-auto rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60';
const classeCancelar =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">
            Homologar exige todos os itens marcados como ok. Não homologar exige ao menos um item com problema ou o motivo escrito; o proponente vê o
            resultado e o motivo no portal e pode recorrer no prazo.
        </p>

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <button
                type="button"
                :disabled="!podeHomologar || form.processing"
                :aria-describedby="podeHomologar ? undefined : 'homologar-por-que'"
                class="botao-primario disabled:cursor-not-allowed!"
                @click="abrirHomologar"
            >
                Homologar
            </button>
            <button type="button" :disabled="!podeConferir || form.processing" class="botao-secundario disabled:cursor-not-allowed!" @click="abrirNaoHomologar">
                Não homologar
            </button>
        </div>
        <p v-if="!podeHomologar" id="homologar-por-que" class="text-[0.8125rem] leading-[1.5] text-tinta-suave">{{ porQueNaoHomologa }}</p>

        <dialog ref="dialogoHomologar" aria-labelledby="homologar-titulo" aria-describedby="homologar-aviso" :class="[classeDialogo, 'w-[min(28rem,calc(100vw-2rem))]']">
            <h2 id="homologar-titulo" class="text-[1.25rem] leading-tight font-bold">Homologar a inscrição?</h2>
            <p id="homologar-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">A decisão fica registrada com seu nome e a data, e as marcações não podem mais ser alteradas.</p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" autofocus :class="classeCancelar" @click="fechar">Cancelar</button>
                <button type="button" :disabled="form.processing" class="botao-primario" @click="decidir('homologada')">
                    {{ form.processing ? 'Homologando…' : 'Homologar' }}
                </button>
            </div>
        </dialog>

        <dialog ref="dialogoNaoHomologar" aria-labelledby="nao-homologar-titulo" aria-describedby="nao-homologar-aviso" :class="[classeDialogo, 'w-[min(36rem,calc(100vw-2rem))]']">
            <form id="formulario-nao-homologar" novalidate class="flex flex-col gap-5" @submit.prevent="naoHomologar">
                <div>
                    <h2 id="nao-homologar-titulo" class="text-[1.25rem] leading-tight font-bold">Não homologar a inscrição?</h2>
                    <p id="nao-homologar-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">
                        O proponente vê o motivo no portal e pode recorrer no prazo. A decisão fica registrada com seu nome e a data.
                    </p>
                </div>

                <CampoCadastro
                    id="motivo"
                    rotulo="Motivo"
                    :obrigatorio="motivoObrigatorio"
                    :ajuda="motivoObrigatorio ? 'Nenhum item está com problema: escreva por que a inscrição não é homologada.' : 'Montado dos itens com problema; ajuste se precisar.'"
                    :erro="form.errors.motivo"
                    v-slot="{ descricao, invalido }"
                >
                    <textarea
                        id="motivo"
                        v-model="form.motivo"
                        rows="8"
                        :maxlength="LIMITE_MOTIVO"
                        :required="motivoObrigatorio"
                        class="campo-retaguarda h-auto! py-3 leading-[1.6]"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="[descricao, 'motivo-contador'].filter(Boolean).join(' ')"
                        @input="form.clearErrors('motivo')"
                    />
                </CampoCadastro>
                <p id="motivo-contador" class="-mt-3 text-right text-[0.8125rem] text-tinta-suave tabular-nums">{{ form.motivo.length }} de {{ LIMITE_MOTIVO }} caracteres</p>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" :class="classeCancelar" @click="fechar">Cancelar</button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-erro-texto bg-erro-fundo px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-erro-texto uppercase disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ form.processing ? 'Registrando…' : 'Não homologar' }}
                    </button>
                </div>
            </form>
        </dialog>
    </div>
</template>
