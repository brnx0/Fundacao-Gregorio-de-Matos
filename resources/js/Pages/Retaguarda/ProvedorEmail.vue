<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import Interruptor from '../../Components/Interruptor.vue';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import { classeCampo } from '../../Components/Retaguarda/listaEditavel';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { CriptografiaEmail, RetaguardaProvedorEmailProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaProvedorEmailProps>();

// Caminhos das rotas retaguarda.email.update e .teste (o front não tem helper de rotas nomeadas).
const URL_EMAIL = '/retaguarda/email';
const URL_TESTE = '/retaguarda/email/teste';

/** Porta usual de cada criptografia: trocar uma leva a outra, se a porta ainda for a usual da anterior. */
const PORTA_USUAL: Record<CriptografiaEmail, number> = { tls: 587, ssl: 465 };

// A senha nunca volta do servidor: o campo começa vazio e, em branco, mantém a guardada.
const form = useForm({ ...props.configuracao, senha: '' });
const senhaVisivel = ref<boolean>(false);
const testando = ref<boolean>(false);

watch(
    () => form.criptografia,
    (atual, anterior) => {
        if (form.porta === PORTA_USUAL[anterior]) {
            form.porta = PORTA_USUAL[atual];
        }
        form.clearErrors('criptografia', 'porta');
    },
);

// O put preserva o estado da página: a senha digitada sai do campo à mão depois de salvar.
const salvar = (): void => {
    form.put(URL_EMAIL, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('senha');
            senhaVisivel.value = false;
            form.defaults();
        },
        onError: () => focarPrimeiroErro('#formulario-email'),
    });
};

const enviarTeste = (): void => {
    router.post(URL_TESTE, {}, { preserveScroll: true, onStart: () => (testando.value = true), onFinish: () => (testando.value = false) });
};

const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
const classeRadio = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 pt-8 lg:px-6">
        <Head title="E-mail · Retaguarda" />

        <div>
            <p class="text-[0.75rem] font-bold tracking-[0.12em] text-acao uppercase">Parametrização</p>
            <h1 class="mt-1 text-[1.75rem] leading-tight font-bold">E-mail</h1>
            <p class="mt-1 text-[0.9375rem] text-tinta-suave">
                Conta que envia os e-mails do sistema, como confirmação de cadastro, redefinição de senha e convites. A senha fica cifrada no banco e não
                aparece de novo nesta tela.
            </p>
        </div>

        <form id="formulario-email" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <AvisoCamposObrigatorios />

            <SecaoCadastro id="secao-email-servidor" :numero="1" titulo="Servidor">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="7" rx="1.5" />
                        <rect x="3" y="13" width="18" height="7" rx="1.5" />
                        <path d="M7 7.5h.01M7 16.5h.01" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-[1fr_10rem]">
                    <CampoCadastro id="host" rotulo="Servidor SMTP" obrigatorio ajuda="No Gmail: smtp.gmail.com." :erro="form.errors.host" v-slot="{ descricao, invalido }">
                        <input
                            id="host"
                            v-model="form.host"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('host')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="porta" rotulo="Porta" obrigatorio :erro="form.errors.porta" v-slot="{ descricao, invalido }">
                        <input
                            id="porta"
                            v-model.number="form.porta"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="65535"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('porta')"
                        />
                    </CampoCadastro>

                    <fieldset class="md:col-span-2" :aria-describedby="form.errors.criptografia ? 'criptografia-erro' : undefined">
                        <legend class="text-[0.9375rem] font-semibold">Criptografia<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
                        <div class="flex flex-wrap gap-x-6">
                            <label v-for="opcao in props.criptografias" :key="opcao.valor" :class="classeMarcacao">
                                <input
                                    v-model="form.criptografia"
                                    type="radio"
                                    name="criptografia"
                                    :value="opcao.valor"
                                    required
                                    :class="classeRadio"
                                    :aria-invalid="form.errors.criptografia ? true : undefined"
                                />
                                {{ opcao.rotulo }}
                            </label>
                        </div>
                        <p v-if="form.errors.criptografia" id="criptografia-erro" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.criptografia }}</p>
                    </fieldset>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-email-conta" :numero="2" titulo="Conta e remetente">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="m3 7 9 6 9-6" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="usuario" rotulo="Usuário" obrigatorio ajuda="No Gmail, o endereço completo da conta." :erro="form.errors.usuario" v-slot="{ descricao, invalido }">
                        <input
                            id="usuario"
                            v-model="form.usuario"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('usuario')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="senha"
                        rotulo="Senha de app"
                        :obrigatorio="!props.senhaGuardada"
                        :ajuda="
                            props.senhaGuardada
                                ? 'Há uma senha guardada. Deixe em branco para manter, ou digite outra para trocar.'
                                : 'O Gmail não aceita a senha da conta: gere uma senha de app em myaccount.google.com/apppasswords (exige verificação em duas etapas).'
                        "
                        :erro="form.errors.senha"
                        v-slot="{ descricao, invalido }"
                    >
                        <div class="relative">
                            <!-- new-password e os data-*: o navegador e os gerenciadores de senha não preenchem aqui a senha da retaguarda. -->
                            <input
                                id="senha"
                                v-model="form.senha"
                                name="email-senha-de-app"
                                :type="senhaVisivel ? 'text' : 'password'"
                                autocomplete="new-password"
                                autocapitalize="off"
                                spellcheck="false"
                                maxlength="255"
                                :required="!props.senhaGuardada"
                                data-1p-ignore
                                data-lpignore="true"
                                data-bwignore
                                data-form-type="other"
                                :placeholder="props.senhaGuardada ? '•••••••••• (configurada)' : ''"
                                :class="[classeCampo, 'pr-12']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="form.clearErrors('senha')"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center text-acao"
                                aria-label="Mostrar senha"
                                aria-controls="senha"
                                :aria-pressed="senhaVisivel ? 'true' : 'false'"
                                @click="senhaVisivel = !senhaVisivel"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                    </CampoCadastro>

                    <CampoCadastro
                        id="remetente_email"
                        rotulo="E-mail do remetente"
                        obrigatorio
                        ajuda="No Gmail, o mesmo da conta (ou um alias dela)."
                        :erro="form.errors.remetente_email"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="remetente_email"
                            v-model="form.remetente_email"
                            type="email"
                            maxlength="255"
                            autocomplete="off"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('remetente_email')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="remetente_nome"
                        rotulo="Nome do remetente"
                        obrigatorio
                        ajuda="Como aparece para quem recebe."
                        :erro="form.errors.remetente_nome"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="remetente_nome"
                            v-model="form.remetente_nome"
                            type="text"
                            maxlength="120"
                            autocomplete="off"
                            placeholder="Fundação Gregório de Mattos"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('remetente_nome')"
                        />
                    </CampoCadastro>

                    <div class="md:col-span-2">
                        <Interruptor
                            id="email-ativo"
                            v-model="form.ativo"
                            rotulo="Enviar os e-mails do sistema por esta conta"
                            ajuda="Desligado, o sistema segue com a configuração do servidor (.env). Teste antes de ligar."
                            :erro="form.errors.ativo"
                            @change="form.clearErrors('ativo')"
                        />
                    </div>
                </div>
            </SecaoCadastro>

            <div class="flex flex-col gap-2 sm:items-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill bg-acao px-8 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                >
                    {{ form.processing ? 'Salvando…' : 'Salvar configuração' }}
                </button>
                <p v-if="form.hasErrors" role="alert" class="text-[0.9375rem] font-semibold text-invalido-texto">Confira os campos destacados acima.</p>
            </div>
        </form>

        <section aria-labelledby="titulo-teste" class="flex flex-col gap-3 rounded-card border border-hairline bg-superficie p-5">
            <h2 id="titulo-teste" class="text-[1.125rem] font-bold">Testar o envio</h2>
            <p id="ajuda-teste" class="text-[0.9375rem] text-tinta-suave">
                Envia um e-mail para <strong class="text-home-tinta">{{ props.emailDoTeste }}</strong> com a configuração salva, mesmo com o envio desligado.
                <template v-if="form.isDirty"> Salve as alterações antes de testar.</template>
            </p>
            <div>
                <button
                    type="button"
                    :disabled="testando || form.isDirty || form.processing || !props.senhaGuardada"
                    aria-describedby="ajuda-teste"
                    class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill border border-tinta-suave/70 bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-home-tinta uppercase hover:border-acao hover:text-acao disabled:cursor-not-allowed disabled:opacity-60"
                    @click="enviarTeste"
                >
                    {{ testando ? 'Enviando…' : 'Enviar e-mail de teste' }}
                </button>
            </div>
        </section>
    </div>
</template>
