<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import { classeCampo } from '../../../Components/Retaguarda/listaEditavel';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaAvaliadoresFormProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliadoresFormProps>();

// Caminhos das rotas retaguarda.avaliadores.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/avaliadores';

const salvo = props.avaliador;
const editando = salvo !== null;

/** 52998224725 → 529.982.247-25 enquanto se digita. */
const formatarCpf = (valor: string): string =>
    valor
        .replace(/\D/g, '')
        .slice(0, 11)
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');

// Data no formato do campo de data (AAAA-MM-DD): o limite impede escolher hoje ou depois.
const ontem = ((): string => {
    const data = new Date();
    data.setDate(data.getDate() - 1);
    return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}-${String(data.getDate()).padStart(2, '0')}`;
})();

const form = useForm({
    nome: salvo?.nome ?? '',
    data_nascimento: salvo?.data_nascimento ?? '',
    cpf: salvo?.cpf ?? '',
    email: salvo?.email ?? '',
});

// Atualiza o próprio campo: se a máscara descarta o que foi digitado, o valor do form não muda e o Vue não redesenha.
const digitarCpf = (evento: Event): void => {
    const campo = evento.target as HTMLInputElement;
    campo.value = formatarCpf(campo.value);
    form.cpf = campo.value;
    form.clearErrors('cpf');
};

const salvar = (): void => {
    const opcoes = {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-avaliador'),
    };

    if (editando) {
        form.put(`${BASE}/${salvo.id}`, opcoes);
    } else {
        form.post(BASE, opcoes);
    }
};
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        :titulo="salvo?.nome ?? 'Novo avaliador'"
        :trilha="[{ texto: editando ? 'Editar' : 'Novo' }]"
        :descricao="salvo && !salvo.ativo ? 'Este avaliador está desativado.' : undefined"
    >
        <Head :title="editando ? 'Editar avaliador · Retaguarda' : 'Novo avaliador · Retaguarda'" />

        <form id="formulario-avaliador" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-avaliador-dados" :numero="1" titulo="Dados do avaliador">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21a8 8 0 0 1 16 0" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <CampoCadastro id="nome" rotulo="Nome completo" obrigatorio :erro="form.errors.nome" v-slot="{ descricao, invalido }">
                            <input
                                id="nome"
                                v-model="form.nome"
                                type="text"
                                maxlength="120"
                                autocomplete="off"
                                required
                                class="campo-retaguarda"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="form.clearErrors('nome')"
                            />
                        </CampoCadastro>
                    </div>

                    <CampoCadastro id="data_nascimento" rotulo="Data de nascimento" obrigatorio :erro="form.errors.data_nascimento" v-slot="{ descricao, invalido }">
                        <input
                            id="data_nascimento"
                            v-model="form.data_nascimento"
                            type="date"
                            min="1900-01-02"
                            :max="ontem"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('data_nascimento')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="cpf" rotulo="CPF" obrigatorio :erro="form.errors.cpf" v-slot="{ descricao, invalido }">
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

                    <div class="md:col-span-2">
                        <CampoCadastro id="email" rotulo="E-mail" obrigatorio :erro="form.errors.email" v-slot="{ descricao, invalido }">
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
                </div>
            </SecaoCadastro>
            <BarraAcoes :cancelar="BASE" :salvar="form.processing ? 'Salvando…' : 'Salvar avaliador'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
