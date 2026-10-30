<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CampoCadastro from '../../CampoCadastro.vue';
import IconeAcao from '../IconeAcao.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';
import type { RecursoDaHomologacao } from '../../../types';

const props = defineProps<{
    inscricaoId: number;
    recurso: RecursoDaHomologacao;
    /** Em recurso: o julgamento não depende do período. */
    podeJulgar: boolean;
}>();

const LIMITE_PARECER = 4000;
const FORMULARIO = '#formulario-julgar-recurso';

const form = useForm<{ decisao: 'aceito' | 'negado'; parecer: string }>({ decisao: 'aceito', parecer: '' });

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogo = ref<HTMLDialogElement | null>(null);
const fechar = (): void => dialogo.value?.close();

// O parecer é conferido antes da pergunta (texto só com espaços conta como vazio, como no servidor).
const confirmar = (decisao: 'aceito' | 'negado'): void => {
    if (form.parecer.trim() === '') {
        form.setError('parecer', 'Escreva o parecer.');
        focarPrimeiroErro(FORMULARIO);
        return;
    }
    form.decisao = decisao;
    dialogo.value?.showModal();
};

// Caminho da rota retaguarda.homologacao.recurso.julgar (o front não tem helper de rotas nomeadas).
const julgar = (): void => {
    form.transform((dados) => ({ decisao: dados.decisao, parecer: dados.parecer.trim() })).post(`/retaguarda/homologacao/${props.inscricaoId}/recurso`, {
        preserveScroll: true,
        // Julgado, o formulário sai da tela e o julgamento entra: o foco vai para o conteúdo, onde a mensagem aparece.
        onSuccess: () => {
            fechar();
            void nextTick(() => document.getElementById('conteudo')?.focus());
        },
        onError: () => {
            fechar();
            focarPrimeiroErro(FORMULARIO);
        },
    });
};

const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
const classeCancelar =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <div class="flex flex-col gap-6">
        <dl class="flex flex-col gap-1.5">
            <dt :class="classeTermo">Justificativa do proponente<template v-if="recurso.enviadoEm"> · enviada em {{ recurso.enviadoEm }}</template></dt>
            <dd v-if="recurso.justificativa" class="break-words whitespace-pre-line">{{ recurso.justificativa }}</dd>
            <dd v-else class="text-tinta-suave italic">Sem justificativa escrita.</dd>
        </dl>

        <section v-if="recurso.documentos.length > 0" aria-labelledby="recurso-documentos" class="flex flex-col gap-3">
            <h3 id="recurso-documentos" class="text-[1.0625rem] font-bold">Documentos trocados</h3>
            <ul class="flex flex-col gap-4">
                <li v-for="documento in recurso.documentos" :key="documento.id" class="rounded-[16px] border border-hairline p-4">
                    <p class="font-semibold break-words">{{ documento.nome }}</p>
                    <div class="mt-3 grid gap-3 @lg:grid-cols-2">
                        <div class="rounded-[12px] bg-parchment p-3">
                            <p :class="classeTermo">Original</p>
                            <div v-if="documento.original" class="flex items-center gap-1">
                                <span class="min-w-0 flex-1 truncate" :title="documento.original.nome">{{ documento.original.nome }}</span>
                                <a :href="documento.original.url" target="_blank" rel="noopener" class="acao-icone" data-dica="Abrir em nova aba">
                                    <IconeAcao nome="abrir" />
                                    <span class="sr-only">Abrir em nova aba o original de {{ documento.nome }}</span>
                                </a>
                            </div>
                            <p v-else class="text-tinta-suave">Não enviado</p>
                        </div>
                        <div class="rounded-[12px] bg-nevoa p-3">
                            <p :class="classeTermo">Novo, do recurso</p>
                            <div class="flex items-center gap-1">
                                <span class="min-w-0 flex-1 truncate" :title="documento.novo.nome">{{ documento.novo.nome }}</span>
                                <a :href="documento.novo.url" target="_blank" rel="noopener" class="acao-icone" data-dica="Abrir em nova aba">
                                    <IconeAcao nome="abrir" />
                                    <span class="sr-only">Abrir em nova aba o novo de {{ documento.nome }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="recurso.decisao" aria-labelledby="recurso-julgamento" class="flex flex-col gap-3 border-t border-hairline pt-6">
            <h3 id="recurso-julgamento" class="text-[1.0625rem] font-bold">Julgamento</h3>
            <p>
                <span class="selo" :class="recurso.decisao === 'aceito' ? 'selo-positivo' : ''">{{ recurso.decisaoRotulo }}</span>
            </p>
            <dl class="flex flex-col gap-3">
                <div>
                    <dt :class="classeTermo">Parecer</dt>
                    <dd class="break-words whitespace-pre-line">{{ recurso.parecer }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Julgado</dt>
                    <dd>
                        <template v-if="recurso.julgadoPor">por {{ recurso.julgadoPor }}</template>
                        <template v-if="recurso.julgadoEm"> em {{ recurso.julgadoEm }}</template>
                    </dd>
                </div>
            </dl>
        </section>

        <form v-else-if="podeJulgar" id="formulario-julgar-recurso" novalidate class="flex flex-col gap-4 border-t border-hairline pt-6" @submit.prevent>
            <CampoCadastro id="parecer" rotulo="Parecer" obrigatorio ajuda="O proponente vê o parecer no portal." :erro="form.errors.parecer" v-slot="{ descricao, invalido }">
                <textarea
                    id="parecer"
                    v-model="form.parecer"
                    rows="6"
                    :maxlength="LIMITE_PARECER"
                    required
                    class="campo-retaguarda h-auto! py-3 leading-[1.6]"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="[descricao, 'parecer-contador'].filter(Boolean).join(' ')"
                    @input="form.clearErrors('parecer')"
                />
            </CampoCadastro>
            <p id="parecer-contador" class="-mt-3 text-right text-[0.8125rem] text-tinta-suave tabular-nums">{{ form.parecer.length }} de {{ LIMITE_PARECER }} caracteres</p>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <button type="button" :disabled="form.processing" class="botao-primario" @click="confirmar('aceito')">Aceitar recurso</button>
                <button type="button" :disabled="form.processing" class="botao-secundario" @click="confirmar('negado')">Negar recurso</button>
            </div>
        </form>

        <p v-else class="text-tinta-suave">O recurso aguarda julgamento.</p>

        <dialog
            ref="dialogo"
            aria-labelledby="julgar-titulo"
            aria-describedby="julgar-aviso"
            class="dialogo-retaguarda m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <h2 id="julgar-titulo" class="text-[1.25rem] leading-tight font-bold">{{ form.decisao === 'aceito' ? 'Aceitar o recurso?' : 'Negar o recurso?' }}</h2>
            <p id="julgar-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">
                {{
                    form.decisao === 'aceito'
                        ? 'A inscrição passa a homologada. O julgamento fica registrado com seu nome, a data e o parecer.'
                        : 'A inscrição fica com o recurso negado. O julgamento fica registrado com seu nome, a data e o parecer.'
                }}
            </p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" autofocus :class="classeCancelar" @click="fechar">Cancelar</button>
                <button type="button" :disabled="form.processing" class="botao-primario" @click="julgar">
                    {{ form.processing ? 'Registrando…' : form.decisao === 'aceito' ? 'Aceitar recurso' : 'Negar recurso' }}
                </button>
            </div>
        </dialog>
    </div>
</template>
