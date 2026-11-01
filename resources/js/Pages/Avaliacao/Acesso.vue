<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import { formatarCpf } from '../../Components/Organizacao/mascaras';
import AvaliacaoLayout from '../../Layouts/AvaliacaoLayout.vue';

defineOptions({ layout: AvaliacaoLayout });

// Rota avaliacao.acesso.confirmar: o mesmo endereço desta página. O código do link não vem nas props.
const endereco = usePage().url.split('?')[0];

const formulario = useForm({ cpf: '' });

const confirmar = (): void => {
    formulario.post(endereco, {
        onError: () => focarPrimeiroErro('#formulario-acesso'),
    });
};

const classeCampo =
    'h-12 w-full rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 text-base text-home-tinta placeholder:text-tinta-suave';
</script>

<template>
    <div>
        <Head title="Acesso do avaliador" />

        <section class="home-escuro relative overflow-hidden bg-noite pt-10 pb-36 text-white md:pt-14 md:pb-40" aria-labelledby="acesso-titulo">
            <div class="relative mx-auto flex max-w-6xl flex-col gap-3 px-4">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Área do avaliador</p>
                <h1 id="acesso-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Confirme seu CPF
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    Este é o seu link pessoal de avaliador. Para entrar, informe o seu CPF.
                </p>
            </div>
        </section>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <section
                aria-label="Confirmação do CPF"
                class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10"
            >
                <form id="formulario-acesso" novalidate class="flex max-w-md flex-col gap-5" @submit.prevent="confirmar">
                    <AvisoCamposObrigatorios />

                    <CampoCadastro id="acesso-cpf" rotulo="CPF" obrigatorio :erro="formulario.errors.cpf" v-slot="{ descricao, invalido }">
                        <input
                            id="acesso-cpf"
                            :value="formulario.cpf"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            maxlength="14"
                            required
                            placeholder="000.000.000-00"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="
                                formulario.cpf = formatarCpf(($event.target as HTMLInputElement).value);
                                formulario.clearErrors('cpf');
                            "
                        />
                    </CampoCadastro>

                    <div class="flex">
                        <button
                            type="submit"
                            :disabled="formulario.processing"
                            class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill bg-acao px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                        >
                            {{ formulario.processing ? 'Entrando…' : 'Entrar' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
