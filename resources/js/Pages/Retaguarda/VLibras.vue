<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaVLibrasProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaVLibrasProps>();

// Caminho da rota retaguarda.vlibras.update (o front não tem helper de rotas nomeadas).
const URL_VLIBRAS = '/retaguarda/vlibras';

const form = useForm({ ...props.configuracao });

const salvar = (): void => {
    form.put(URL_VLIBRAS, {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-vlibras'),
    });
};

const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        titulo="VLibras"
        descricao="Tradutor de português para Libras do governo federal. Aparece no portal e na área do proponente, nunca na retaguarda. As mudanças valem a partir da próxima página aberta no portal."
    >
        <Head title="VLibras · Retaguarda" />

        <template #acoes>
            <!-- Link comum, não <Link>: o portal abre em outra aba, fora da retaguarda. -->
            <a href="/" target="_blank" rel="noopener" class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase">
                Abrir o portal
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
                </svg>
                <span class="sr-only">(abre em nova aba)</span>
            </a>
        </template>

        <form id="formulario-vlibras" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-vlibras" :numero="1" titulo="VLibras no portal">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path
                            d="M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"
                        />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-y-5">
                    <div>
                        <label :class="classeMarcacao">
                            <input
                                v-model="form.ativo"
                                type="checkbox"
                                :class="classeCaixa"
                                :aria-invalid="form.errors.ativo ? true : undefined"
                                :aria-describedby="form.errors.ativo ? 'ativo-ajuda ativo-erro' : 'ativo-ajuda'"
                                @change="form.clearErrors('ativo')"
                            />
                            <span>
                                <span class="font-semibold">Mostrar o VLibras no portal</span>
                                <span id="ativo-ajuda" class="block text-[0.8125rem] text-tinta-suave">Desligado, o botão do VLibras some de todas as páginas.</span>
                            </span>
                        </label>
                        <p v-if="form.errors.ativo" id="ativo-erro" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.ativo }}</p>
                    </div>

                    <fieldset :aria-describedby="form.errors.lado ? 'lado-erro' : undefined">
                        <legend class="text-[0.9375rem] font-semibold">Lado da tela<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
                        <div class="flex flex-wrap gap-x-6">
                            <label v-for="lado in props.lados" :key="lado.valor" :class="classeMarcacao">
                                <input
                                    v-model="form.lado"
                                    type="radio"
                                    name="lado"
                                    :value="lado.valor"
                                    required
                                    :class="classeCaixa"
                                    :aria-invalid="form.errors.lado ? true : undefined"
                                    @change="form.clearErrors('lado')"
                                />
                                {{ lado.rotulo }}
                            </label>
                        </div>
                        <p v-if="form.errors.lado" id="lado-erro" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.lado }}</p>
                    </fieldset>

                    <fieldset :aria-describedby="form.errors.avatar ? 'avatar-ajuda avatar-erro' : 'avatar-ajuda'">
                        <legend class="text-[0.9375rem] font-semibold">Avatar padrão<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
                        <p id="avatar-ajuda" class="text-[0.8125rem] text-tinta-suave">O intérprete com que o VLibras abre para o visitante.</p>
                        <div class="flex flex-wrap gap-x-6">
                            <label v-for="avatar in props.avatares" :key="avatar.valor" :class="classeMarcacao">
                                <input
                                    v-model="form.avatar"
                                    type="radio"
                                    name="avatar"
                                    :value="avatar.valor"
                                    required
                                    :class="classeCaixa"
                                    :aria-invalid="form.errors.avatar ? true : undefined"
                                    @change="form.clearErrors('avatar')"
                                />
                                {{ avatar.rotulo }}
                            </label>
                        </div>
                        <p v-if="form.errors.avatar" id="avatar-erro" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.avatar }}</p>
                    </fieldset>
                </div>
            </SecaoCadastro>
            <BarraAcoes :salvar="form.processing ? 'Salvando…' : 'Salvar'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
