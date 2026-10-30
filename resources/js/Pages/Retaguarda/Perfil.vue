<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaPerfilProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaPerfilProps>();

// Caminhos das rotas retaguarda.perfil.update e retaguarda.senha.edit (o front não tem helper de rotas nomeadas).
const URL_PERFIL = '/retaguarda/perfil';
const URL_SENHA = '/retaguarda/minha-senha';

// (71) 99999-9999 para celular e (71) 3333-3333 para fixo, como no Cadastro/Completar.
const formatarTelefone = (valor: string): string => {
    const digitos = valor.replace(/\D/g, '').slice(0, 11);
    if (digitos.length <= 2) return digitos ? `(${digitos}` : '';
    if (digitos.length <= 6) return `(${digitos.slice(0, 2)}) ${digitos.slice(2)}`;
    const corte = digitos.length === 11 ? 7 : 6;
    return `(${digitos.slice(0, 2)}) ${digitos.slice(2, corte)}-${digitos.slice(corte)}`;
};

const form = useForm({
    name: props.perfil.name,
    email: props.perfil.email,
    telefone: formatarTelefone(props.perfil.telefone ?? ''),
    senha_atual: '',
});

// O e-mail é o login: trocá-lo pede a senha atual (quem só entra pelo gov.br não tem senha).
const emailMudou = computed<boolean>(() => form.email.trim().toLowerCase() !== props.perfil.email);
const pedeSenha = computed<boolean>(() => props.temSenha && emailMudou.value);

const mascararTelefone = (evento: Event): void => {
    const campo = evento.target as HTMLInputElement;
    form.telefone = formatarTelefone(campo.value);
    campo.value = form.telefone;
    form.clearErrors('telefone');
};

const salvar = (): void => {
    form.put(URL_PERFIL, {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-perfil'),
        onSuccess: () => form.defaults({ name: form.name, email: form.email.trim().toLowerCase(), telefone: form.telefone, senha_atual: '' }),
        onFinish: () => form.reset('senha_atual'),
    });
};

const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
</script>

<template>
    <PaginaRetaguarda largura="estreita" titulo="Meu perfil" grupo="Minha conta" descricao="Seus dados de acesso à retaguarda.">
        <Head title="Meu perfil · Retaguarda" />

        <form id="formulario-perfil" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-dados" :numero="1" titulo="Seus dados">
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
                            autocomplete="name"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('name')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="email" rotulo="E-mail" obrigatorio ajuda="É o seu login na retaguarda." :erro="form.errors.email" v-slot="{ descricao, invalido }">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('email', 'senha_atual')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="telefone" rotulo="Telefone" :erro="form.errors.telefone" v-slot="{ descricao, invalido }">
                        <input
                            id="telefone"
                            :value="form.telefone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="(71) 99999-9999"
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascararTelefone"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        v-if="pedeSenha"
                        id="senha_atual"
                        class="md:col-span-2"
                        rotulo="Senha atual"
                        obrigatorio
                        ajuda="Para trocar o e-mail, confirme com a sua senha."
                        :erro="form.errors.senha_atual"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="senha_atual"
                            v-model="form.senha_atual"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('senha_atual')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-acesso" :numero="2" titulo="Acesso">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z" />
                        <path d="m9 12 2 2 4-4" />
                    </svg>
                </template>

                <dl class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <dt class="text-[0.9375rem] font-semibold">CPF</dt>
                        <dd :class="classeLeitura" class="tabular-nums">{{ props.perfil.cpf }}</dd>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <dt class="text-[0.9375rem] font-semibold">Papel</dt>
                        <dd :class="classeLeitura">{{ props.perfil.papeis.join(', ') || '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-[0.875rem] text-tinta-suave">
                    CPF e papel só podem ser alterados por um administrador, na tela Usuários. Para mudar a senha, use
                    <Link :href="URL_SENHA" class="font-semibold text-acao underline">Trocar senha</Link>.
                </p>
            </SecaoCadastro>
            <BarraAcoes :salvar="form.processing ? 'Salvando…' : 'Salvar perfil'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
