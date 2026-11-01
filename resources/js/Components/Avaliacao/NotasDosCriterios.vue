<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CampoCadastro from '../CampoCadastro.vue';
import { focarPrimeiroErro } from '../focarPrimeiroErro';
import { caracteres } from '../Inscricao/questionario';
import type { CriterioDaAvaliacao } from '../../types';

const props = defineProps<{
    inscricaoId: number;
    criterios: CriterioDaAvaliacao[];
    /** Notas gravadas pelo id do critério, com vírgula decimal. */
    notas: Record<number, string>;
    observacao: string | null;
    /** Não concluída e dentro do período: senão, só leitura. */
    podeAvaliar: boolean;
    notaFinal: string | null;
}>();

// Mesmos limites e mensagens do servidor (SalvarNotasRequest, InscricoesDoAvaliadorController).
const LIMITE_DA_OBSERVACAO = 4000;
const ERRO_SEM_NOTA = 'Dê nota a este critério para concluir.';
const CONFIRMACAO = 'Depois de concluir, as notas não podem mais ser alteradas.';
const FORMULARIO = '#formulario-notas';

// Caminhos das rotas avaliacao.inscricoes.notas e avaliacao.inscricoes.conclusao (o front não tem helper de rotas).
const URL_DAS_NOTAS = `/avaliacao/inscricoes/${props.inscricaoId}/notas`;
const URL_DA_CONCLUSAO = `/avaliacao/inscricoes/${props.inscricaoId}/conclusao`;

const form = useForm<{ notas: Record<number, string>; observacao: string }>({
    notas: Object.fromEntries(props.criterios.map((criterio) => [criterio.id, props.notas[criterio.id] ?? ''])),
    observacao: props.observacao ?? '',
});

// Erros por caminho ("notas.12"), que o tipo do useForm não descreve.
const erros = computed<Record<string, string | undefined>>(() => form.errors as Record<string, string | undefined>);

const usados = computed<number>(() => caracteres(form.observacao));
const passouDoLimite = computed<boolean>(() => usados.value > LIMITE_DA_OBSERVACAO);
const numero = (valor: number): string => valor.toLocaleString('pt-BR');

const idDaNota = (criterioId: number): string => `nota-${criterioId}`;

const ajudaDoCriterio = (criterio: CriterioDaAvaliacao): string =>
    [criterio.pontosMaximos === null ? null : `De 0 a ${criterio.pontosMaximos}`, `peso ${criterio.peso}`].filter(Boolean).join(' · ');

const limparErro = (campo: string): void => {
    form.clearErrors(campo as keyof typeof form.errors);
};

const alterarNota = (criterioId: number, valor: string): void => {
    form.notas = { ...form.notas, [criterioId]: valor };
    limparErro(`notas.${criterioId}`);
    limparErro('notas');
};

// Em branco sai nulo: o servidor apaga a nota deixada em branco.
const dados = () => ({
    notas: Object.fromEntries(Object.entries(form.notas).map(([id, valor]) => [id, valor.trim() === '' ? null : valor.trim()])),
    observacao: form.observacao,
});

const salvar = (): void => {
    if (passouDoLimite.value) {
        focarPrimeiroErro(FORMULARIO);
        return;
    }
    form.transform(dados).put(URL_DAS_NOTAS, { preserveScroll: true, onError: () => focarPrimeiroErro(FORMULARIO) });
};

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogo = ref<HTMLDialogElement | null>(null);
const fecharDialogo = (): void => dialogo.value?.close();

// Concluir exige nota em todos os critérios, como no servidor: o que falta fica destacado antes da confirmação.
const confirmarConclusao = (): void => {
    const semNota = props.criterios.filter((criterio) => (form.notas[criterio.id] ?? '').trim() === '');
    if (semNota.length > 0) {
        form.setError(Object.fromEntries(semNota.map((criterio) => [`notas.${criterio.id}`, ERRO_SEM_NOTA])) as typeof form.errors);
        focarPrimeiroErro(FORMULARIO);
        return;
    }
    if (passouDoLimite.value) {
        focarPrimeiroErro(FORMULARIO);
        return;
    }
    dialogo.value?.showModal();
};

const concluir = (): void => {
    form.transform(dados).post(URL_DA_CONCLUSAO, {
        preserveScroll: true,
        // Fechar antes de focar: ao fechar, o diálogo devolve o foco ao botão que abriu.
        onError: () => {
            fecharDialogo();
            focarPrimeiroErro(FORMULARIO);
        },
        onFinish: fecharDialogo,
    });
};

const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
const classeDialogo = 'dialogo-retaguarda m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60';
const classeCancelar =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <div class="flex flex-col gap-6">
        <p v-if="criterios.length === 0" class="text-tinta-suave">O edital não tem critérios para esta inscrição.</p>

        <form v-if="podeAvaliar" id="formulario-notas" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">
                Dê a nota de cada critério, de 0 até a pontuação máxima, com até 2 casas decimais. Salve o rascunho quantas vezes quiser; para concluir, todos os critérios precisam
                de nota.
            </p>
            <p v-if="erros.notas" role="alert" class="rounded-[12px] border border-invalido-texto/40 bg-invalido-fundo px-4 py-3 font-semibold text-invalido-texto">
                {{ erros.notas }}
            </p>

            <ul class="flex flex-col gap-4">
                <li v-for="criterio in criterios" :key="criterio.id" class="flex flex-col gap-3 rounded-[16px] border border-hairline p-5">
                    <p v-if="criterio.descricao" class="text-[0.9375rem] leading-[1.6] break-words text-tinta-suave">{{ criterio.descricao }}</p>
                    <CampoCadastro
                        :id="idDaNota(criterio.id)"
                        :rotulo="criterio.nome"
                        obrigatorio
                        :ajuda="ajudaDoCriterio(criterio)"
                        :erro="erros[`notas.${criterio.id}`]"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idDaNota(criterio.id)"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            :value="form.notas[criterio.id]"
                            class="campo-retaguarda max-w-40 tabular-nums"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="alterarNota(criterio.id, ($event.target as HTMLInputElement).value)"
                        />
                    </CampoCadastro>
                </li>
            </ul>

            <CampoCadastro id="observacao" rotulo="Observação" ajuda="Opcional. Fica com a avaliação." :erro="erros.observacao" v-slot="{ descricao, invalido }">
                <textarea
                    id="observacao"
                    v-model="form.observacao"
                    rows="6"
                    class="campo-retaguarda h-auto! py-3 leading-[1.6]"
                    :aria-invalid="invalido || passouDoLimite || undefined"
                    :aria-describedby="[descricao, 'observacao-contador'].filter(Boolean).join(' ')"
                    @input="limparErro('observacao')"
                ></textarea>
                <p id="observacao-contador" class="text-[0.8125rem] tabular-nums" :class="passouDoLimite ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'">
                    {{ numero(usados) }} de {{ numero(LIMITE_DA_OBSERVACAO) }} caracteres<template v-if="passouDoLimite"> (passou do limite)</template>
                </p>
            </CampoCadastro>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <button type="submit" :disabled="form.processing" class="botao-secundario disabled:cursor-wait">
                    {{ form.processing ? 'Salvando…' : 'Salvar rascunho' }}
                </button>
                <button type="button" :disabled="form.processing || criterios.length === 0" class="botao-primario disabled:cursor-not-allowed!" @click="confirmarConclusao">
                    Concluir avaliação
                </button>
            </div>

            <dialog ref="dialogo" aria-labelledby="concluir-titulo" aria-describedby="concluir-aviso" :class="classeDialogo">
                <h2 id="concluir-titulo" class="text-[1.25rem] leading-tight font-bold">Concluir a avaliação?</h2>
                <p id="concluir-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">{{ CONFIRMACAO }}</p>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" autofocus :class="classeCancelar" @click="fecharDialogo">Cancelar</button>
                    <button type="button" :disabled="form.processing" class="botao-primario" @click="concluir">
                        {{ form.processing ? 'Concluindo…' : 'Concluir' }}
                    </button>
                </div>
            </dialog>
        </form>

        <dl v-else class="flex flex-col gap-4">
            <div v-for="criterio in criterios" :key="criterio.id" class="flex flex-col gap-1.5">
                <dt class="text-[0.9375rem] font-semibold break-words">
                    {{ criterio.nome }} <span class="font-normal text-tinta-suave">({{ ajudaDoCriterio(criterio) }})</span>
                </dt>
                <dd :class="[classeLeitura, notas[criterio.id] === undefined ? 'text-tinta-suave' : 'tabular-nums']">{{ notas[criterio.id] ?? 'Sem nota' }}</dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="text-[0.9375rem] font-semibold">Observação</dt>
                <dd :class="['rounded-[12px] border border-hairline bg-nevoa px-4 py-3 break-words whitespace-pre-line', observacao === null ? 'text-tinta-suave' : '']">
                    {{ observacao ?? 'Sem observação' }}
                </dd>
            </div>
            <div v-if="notaFinal !== null" class="flex flex-col gap-1.5">
                <dt class="text-[0.9375rem] font-semibold">Nota final</dt>
                <dd class="text-[1.5rem] font-bold tabular-nums">{{ notaFinal }}</dd>
            </div>
        </dl>
    </div>
</template>
