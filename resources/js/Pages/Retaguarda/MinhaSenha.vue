<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaMinhaSenhaProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaMinhaSenhaProps>();

// Caminho da rota retaguarda.senha.update (o front não tem helper de rotas nomeadas).
const URL_SENHA = '/retaguarda/minha-senha';

const form = useForm({
    senha_atual: '',
    nova_senha: '',
    nova_senha_confirmation: '',
});

const salvar = (): void => {
    form.put(URL_SENHA, {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-senha'),
        onFinish: () => form.reset(),
    });
};

</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        titulo="Trocar senha"
        grupo="Minha conta"
        :descricao="props.temSenha ? undefined : 'Você entra pelo gov.br e ainda não tem senha. Defina uma para também entrar com e-mail e senha.'"
    >
        <Head title="Trocar senha · Retaguarda" />

        <form id="formulario-senha" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-senha" :numero="1" titulo="Sua senha">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="11" width="14" height="10" rx="2" />
                        <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5">
                    <CampoCadastro v-if="props.temSenha" id="senha_atual" rotulo="Senha atual" obrigatorio :erro="form.errors.senha_atual" v-slot="{ descricao, invalido }">
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

                    <CampoCadastro id="nova_senha" rotulo="Nova senha" obrigatorio ajuda="Mínimo de 8 caracteres." :erro="form.errors.nova_senha" v-slot="{ descricao, invalido }">
                        <input
                            id="nova_senha"
                            v-model="form.nova_senha"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('nova_senha')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="nova_senha_confirmation" rotulo="Confirme a nova senha" obrigatorio v-slot="{ descricao }">
                        <input
                            id="nova_senha_confirmation"
                            v-model="form.nova_senha_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="campo-retaguarda"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>
            <BarraAcoes :salvar="form.processing ? 'Salvando…' : 'Salvar senha'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
