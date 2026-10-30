<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaUsuariosFormProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaUsuariosFormProps>();

// Caminhos das rotas retaguarda.usuarios.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/usuarios';

const salvo = props.usuario;
const editando = salvo !== null;

/** 52998224725 → 529.982.247-25 enquanto se digita. */
const formatarCpf = (valor: string): string =>
    valor
        .replace(/\D/g, '')
        .slice(0, 11)
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');

// Na edição, senha em branco mantém a atual.
const form = useForm({
    name: salvo?.name ?? '',
    email: salvo?.email ?? '',
    cpf: salvo?.cpf ?? '',
    senha: '',
    senha_confirmation: '',
    // Com um papel só (hoje, administrador), ele já vem marcado no cadastro novo.
    papeis: salvo?.papeis ?? (props.papeisDisponiveis.length === 1 ? props.papeisDisponiveis.map((papel) => papel.slug) : []),
    // Marcada quando o CPF é de um proponente e o servidor pede a confirmação.
    confirmar_proponente: false,
});

const pedeConfirmacao = computed<boolean>(() => !editando && (form.errors.confirmar_proponente !== undefined || form.confirmar_proponente));

// Atualiza o próprio campo: se a máscara descarta o que foi digitado, o valor do form não muda e o Vue não redesenha.
const digitarCpf = (evento: Event): void => {
    const campo = evento.target as HTMLInputElement;
    campo.value = formatarCpf(campo.value);
    form.cpf = campo.value;
    // Outro CPF: a confirmação dada para o anterior não vale mais.
    form.confirmar_proponente = false;
    form.clearErrors('cpf', 'confirmar_proponente');
};

// CPF salvo inválido (o do primeiro administrador) chega em branco e precisa ser informado.
const ajudaCpf = !editando
    ? 'Se o CPF já for de um proponente, será pedida a confirmação: ele passa a ser usuário da retaguarda e deixa de acessar o cadastro do portal.'
    : salvo.cpf === ''
      ? 'O CPF salvo não é válido. Informe o CPF correto.'
      : undefined;

const salvar = (): void => {
    const opcoes = {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-usuario'),
        onFinish: () => form.reset('senha', 'senha_confirmation'),
    };

    if (editando) {
        form.put(`${BASE}/${salvo.id}`, opcoes);
    } else {
        form.post(BASE, opcoes);
    }
};

const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        :titulo="salvo?.name ?? 'Novo usuário'"
        :trilha="[{ texto: editando ? 'Editar' : 'Novo' }]"
        :descricao="salvo && !salvo.ativo ? 'Este usuário está desativado e não entra na retaguarda.' : undefined"
    >
        <Head :title="editando ? 'Editar usuário · Retaguarda' : 'Novo usuário · Retaguarda'" />

        <form id="formulario-usuario" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-usuario-dados" :numero="1" titulo="Dados do usuário">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21a8 8 0 0 1 16 0" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="name" class="md:col-span-2" rotulo="Nome" obrigatorio :erro="form.errors.name" v-slot="{ descricao, invalido }">
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('name')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="cpf"
                        rotulo="CPF"
                        obrigatorio
                        :ajuda="ajudaCpf"
                        :erro="form.errors.cpf"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="cpf"
                            :value="form.cpf"
                            type="text"
                            inputmode="numeric"
                            maxlength="14"
                            autocomplete="off"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="digitarCpf"
                        />
                    </CampoCadastro>

                    <div v-if="pedeConfirmacao" class="md:col-span-2">
                        <label :class="classeMarcacao">
                            <input
                                v-model="form.confirmar_proponente"
                                type="checkbox"
                                required
                                :class="classeCaixa"
                                :aria-invalid="form.errors.confirmar_proponente ? true : undefined"
                                :aria-describedby="form.errors.confirmar_proponente ? 'confirmar_proponente-erro' : undefined"
                                @change="form.clearErrors('confirmar_proponente')"
                            />
                            <span>
                                Confirmo transformar este proponente em usuário da retaguarda<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                            </span>
                        </label>
                        <p v-if="form.errors.confirmar_proponente" id="confirmar_proponente-erro" class="text-[0.875rem] font-semibold text-invalido-texto">
                            {{ form.errors.confirmar_proponente }}
                        </p>
                    </div>

                    <CampoCadastro id="email" rotulo="E-mail" obrigatorio ajuda="Usado para entrar na retaguarda." :erro="form.errors.email" v-slot="{ descricao, invalido }">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            maxlength="255"
                            autocomplete="off"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('email')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-usuario-acesso" :numero="2" titulo="Acesso">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="11" width="14" height="10" rx="2" />
                        <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro
                        id="senha"
                        rotulo="Senha"
                        :obrigatorio="!editando"
                        :ajuda="editando ? 'Deixe em branco para manter a senha atual. Mínimo de 8 caracteres.' : 'Mínimo de 8 caracteres.'"
                        :erro="form.errors.senha"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="senha"
                            v-model="form.senha"
                            type="password"
                            autocomplete="new-password"
                            :required="!editando"
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('senha')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="senha_confirmation" rotulo="Confirme a senha" :obrigatorio="!editando" v-slot="{ descricao }">
                        <input
                            id="senha_confirmation"
                            v-model="form.senha_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :required="!editando"
                            class="campo-retaguarda"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <fieldset class="md:col-span-2" :aria-describedby="form.errors.papeis ? 'papeis-erro' : undefined">
                        <legend class="text-[0.9375rem] font-semibold">Papéis<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
                        <label v-for="papel in papeisDisponiveis" :key="papel.slug" :class="classeMarcacao">
                            <input
                                v-model="form.papeis"
                                type="checkbox"
                                :value="papel.slug"
                                :required="form.papeis.length === 0"
                                :class="classeCaixa"
                                :aria-invalid="form.errors.papeis ? true : undefined"
                                @change="form.clearErrors('papeis')"
                            />
                            {{ papel.nome }}
                        </label>
                        <p v-if="form.errors.papeis" id="papeis-erro" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.papeis }}</p>
                    </fieldset>
                </div>
            </SecaoCadastro>
            <BarraAcoes :cancelar="BASE" :salvar="form.processing ? 'Salvando…' : 'Salvar usuário'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
