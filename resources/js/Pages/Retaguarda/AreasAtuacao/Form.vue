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
import type { RetaguardaAreasAtuacaoFormProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAreasAtuacaoFormProps>();

// Caminhos das rotas retaguarda.areas-atuacao.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/areas-atuacao';

const salva = props.area;
const editando = salva !== null;

const form = useForm({ nome: salva?.nome ?? '' });

const salvar = (): void => {
    const opcoes = {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-area'),
    };

    if (editando) {
        form.put(`${BASE}/${salva.id}`, opcoes);
    } else {
        form.post(BASE, opcoes);
    }
};
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        :titulo="salva?.nome ?? 'Nova área de atuação'"
        :trilha="[{ texto: editando ? 'Editar' : 'Nova' }]"
        :descricao="salva && !salva.ativo ? 'Esta área de atuação está desativada e não aparece nas opções.' : undefined"
    >
        <Head :title="editando ? 'Editar área de atuação · Retaguarda' : 'Nova área de atuação · Retaguarda'" />

        <form id="formulario-area" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-area-dados" :numero="1" titulo="Área de atuação">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <CampoCadastro id="nome" rotulo="Nome" obrigatorio ajuda="Ex.: Artes Cênicas." :erro="form.errors.nome" v-slot="{ descricao, invalido }">
                    <input
                        id="nome"
                        v-model="form.nome"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        required
                        class="campo-retaguarda"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="form.clearErrors('nome')"
                    />
                </CampoCadastro>
            </SecaoCadastro>
            <BarraAcoes :cancelar="BASE" :salvar="form.processing ? 'Salvando…' : 'Salvar área de atuação'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
