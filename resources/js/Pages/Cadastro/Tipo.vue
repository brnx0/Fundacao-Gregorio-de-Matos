<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import EtapasCadastro from '../../Components/EtapasCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import SaudacaoCadastro from '../../Components/SaudacaoCadastro.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { CadastroTipoProps, TipoCadastro } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<CadastroTipoProps>();

// Já escolhido antes: a etapa serve para trocar os tipos, e o menu do portal muda junto.
const finalizado = props.tiposAtuais.length > 0;

const descricoes: Record<TipoCadastro, string> = {
    proponente: 'Quero inscrever meus projetos culturais nos editais de fomento.',
    patrocinador: 'Quero conhecer os projetos dos proponentes e encontrar oportunidades de patrocínio.',
};

// Dá para marcar os dois: o menu do portal mostra as telas de cada tipo marcado.
const form = useForm<{ tipos_cadastro: TipoCadastro[] }>({ tipos_cadastro: [...props.tiposAtuais] });

const finalizar = (): void => {
    form.post('/cadastro/tipo', {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-tipo-cadastro'),
    });
};
</script>

<template>
    <div>
        <Head title="Tipo de Cadastro" />

        <FaixaPortal aria-labelledby="tipo-titulo">
            <!-- No modo edição, o mesmo cabeçalho das outras etapas. -->
            <SaudacaoCadastro v-if="finalizado" id-titulo="tipo-titulo" />
            <div v-else class="flex flex-col gap-3">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Etapa 5 de 5 · Tipo de Cadastro</p>
                <h1 id="tipo-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Tipo de Cadastro
                </h1>
            </div>
            <template #abaixo>
                <EtapasCadastro :atual="5" :navegavel="finalizado" />
            </template>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 max-w-6xl px-4 pb-16 md:-mt-24">
            <form
                id="formulario-tipo-cadastro"
                novalidate
                class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10"
                @submit.prevent="finalizar"
            >
                <AvisoCamposObrigatorios class="mb-6" />

                <fieldset :aria-describedby="form.errors.tipos_cadastro ? 'tipo-cadastro-dica tipo-cadastro-erro' : 'tipo-cadastro-dica'">
                    <legend class="text-[1.25rem] leading-tight font-bold text-home-tinta md:text-[1.375rem]">
                        Qual tipo de cadastro deseja realizar?<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                    </legend>
                    <p id="tipo-cadastro-dica" class="mt-2 text-[0.9375rem] text-tinta-suave">Marque um ou os dois.</p>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label
                            v-for="opcao in opcoes"
                            :key="opcao.valor"
                            class="flex cursor-pointer items-start gap-4 rounded-[18px] border border-hairline p-5 transition-colors hover:border-acao has-checked:border-acao has-checked:bg-nevoa has-checked:ring-2 has-checked:ring-acao/25 has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-acao motion-reduce:transition-none"
                        >
                            <input
                                v-model="form.tipos_cadastro"
                                type="checkbox"
                                name="tipos_cadastro"
                                :value="opcao.valor"
                                class="mt-0.5 size-5 shrink-0 cursor-pointer accent-acao focus-visible:outline-none"
                                :aria-invalid="form.errors.tipos_cadastro ? true : undefined"
                                @change="form.clearErrors('tipos_cadastro')"
                            />
                            <span class="flex flex-col gap-1">
                                <span class="text-[1.0625rem] font-bold text-home-tinta">{{ opcao.rotulo }}</span>
                                <span class="text-[0.9375rem] leading-[1.5] text-tinta-suave">{{ descricoes[opcao.valor] }}</span>
                            </span>
                        </label>
                    </div>

                    <p v-if="form.errors.tipos_cadastro" id="tipo-cadastro-erro" class="mt-3 flex items-start gap-1.5 text-[0.875rem] font-semibold text-invalido-texto">
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <circle cx="12" cy="12" r="9" />
                            <path d="m15 9-6 6M9 9l6 6" />
                        </svg>
                        {{ form.errors.tipos_cadastro }}
                    </p>
                </fieldset>

                <div class="mt-8 flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="pressionavel inline-flex min-h-12 w-full items-center justify-center rounded-pill bg-acao px-8 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:opacity-70 sm:w-auto"
                    >
                        {{ form.processing ? 'Finalizando…' : 'Finalizar Cadastro' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
