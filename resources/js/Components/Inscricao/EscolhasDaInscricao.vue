<script setup lang="ts">
import { computed } from 'vue';
import type { CadastroDaInscricao, CategoriaDaInscricao, CotaDaInscricao } from '../../types';
import CampoCadastro from '../CampoCadastro.vue';
import Interruptor from '../Interruptor.vue';

type Campo = 'cadastro' | 'categoria' | 'cotas';

const props = defineProps<{
    cadastros: CadastroDaInscricao[];
    categorias: CategoriaDaInscricao[];
    /** Só as cotas que o edital oferece. */
    cotas: CotaDaInscricao[];
    vedaNegroIndigena: boolean;
    erros: Partial<Record<Campo, string>>;
    /** Chave do cadastro da inscrição que está sendo editada: ela já conta no limite, então não o bloqueia. */
    cadastroAtual?: string;
}>();

const cadastro = defineModel<string>('cadastro', { required: true });
const categoria = defineModel<number | null>('categoria', { required: true });
const cotasEscolhidas = defineModel<string[]>('cotasEscolhidas', { required: true });

const emit = defineEmits<{ limparErro: [campo: Campo] }>();

const LIMITE_ATINGIDO = 'Este cadastro já atingiu o limite de inscrições deste edital.';

// Categorias que a natureza do cadastro pode escolher (lista vazia vale para todas).
const categoriasDaNatureza = (natureza: string | undefined): CategoriaDaInscricao[] =>
    natureza === undefined ? [] : props.categorias.filter((item) => item.naturezas.length === 0 || item.naturezas.includes(natureza));

const cadastroEscolhido = computed<CadastroDaInscricao | undefined>(() => props.cadastros.find((item) => item.chave === cadastro.value));

// Sem nenhuma para a natureza, a inscrição segue sem categoria (como as escolhas aceitam no servidor).
const categoriasDisponiveis = computed<CategoriaDaInscricao[]>(() => categoriasDaNatureza(cadastroEscolhido.value?.natureza));

// Motivo de o cadastro não poder ser escolhido; nulo = pode.
const motivoIndisponivel = (item: CadastroDaInscricao): string | null => {
    if (!item.aceito) {
        return 'O edital não aceita esta natureza de cadastro.';
    }
    if (item.limiteAtingido && item.chave !== props.cadastroAtual) {
        return LIMITE_ATINGIDO;
    }
    return null;
};

const escolherCadastro = (item: CadastroDaInscricao): void => {
    cadastro.value = item.chave;
    if (!categoriasDaNatureza(item.natureza).some((candidata) => candidata.id === categoria.value)) {
        categoria.value = null;
    }
    emit('limparErro', 'cadastro');
    emit('limparErro', 'categoria');
};

// Com a vedação do edital, pessoas negras e indígenas não podem ser marcadas juntas.
const cotaBloqueada = (valor: string): boolean => {
    if (!props.vedaNegroIndigena) {
        return false;
    }
    return (valor === 'negros' && cotasEscolhidas.value.includes('indigenas')) || (valor === 'indigenas' && cotasEscolhidas.value.includes('negros'));
};

const alternarCota = (valor: string, ligado: boolean): void => {
    const outras = cotasEscolhidas.value.filter((cota) => cota !== valor);
    cotasEscolhidas.value = ligado ? [...outras, valor] : outras;
    emit('limparErro', 'cotas');
};

const classeCampo = 'h-12 w-full rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 text-base text-home-tinta';
const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <div class="flex flex-col gap-6">
        <fieldset class="flex min-w-0 flex-col gap-3" :aria-describedby="erros.cadastro ? 'inscricao-cadastro-erro' : undefined">
            <legend class="text-[0.9375rem] font-semibold">Cadastro<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
            <label
                v-for="(item, indice) in cadastros"
                :key="item.chave"
                class="flex items-start gap-3 rounded-[12px] border border-hairline p-4"
                :class="motivoIndisponivel(item) ? 'opacity-70' : 'cursor-pointer'"
            >
                <input
                    type="radio"
                    name="cadastro"
                    :value="item.chave"
                    :checked="cadastro === item.chave"
                    required
                    :disabled="motivoIndisponivel(item) !== null"
                    :aria-invalid="erros.cadastro && motivoIndisponivel(item) === null ? true : undefined"
                    :aria-describedby="`inscricao-cadastro-${indice}-aviso`"
                    :class="classeCaixa"
                    @change="escolherCadastro(item)"
                />
                <span class="min-w-0">
                    <span class="block font-semibold break-words">{{ item.nome }}</span>
                    <span class="block text-[0.875rem] text-tinta-suave">{{ item.naturezaRotulo }} · {{ item.documento }}</span>
                    <span :id="`inscricao-cadastro-${indice}-aviso`" class="block">
                        <span v-if="motivoIndisponivel(item)" class="mt-1 block text-[0.875rem] font-semibold text-invalido-texto">{{ motivoIndisponivel(item) }}</span>
                        <span v-if="item.inscricaoDeOutro" class="mt-1 block text-[0.875rem] text-tinta-suave">
                            Já existe inscrição deste CNPJ: {{ item.inscricaoDeOutro }}.
                        </span>
                    </span>
                </span>
            </label>
            <p v-if="erros.cadastro" id="inscricao-cadastro-erro" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ erros.cadastro }}</p>
        </fieldset>

        <CampoCadastro
            v-if="categoriasDisponiveis.length > 0"
            id="inscricao-categoria"
            rotulo="Categoria"
            obrigatorio
            :erro="erros.categoria"
            v-slot="{ descricao, invalido }"
        >
            <select
                id="inscricao-categoria"
                v-model="categoria"
                required
                :class="classeCampo"
                :aria-invalid="invalido || undefined"
                :aria-describedby="descricao"
                @change="emit('limparErro', 'categoria')"
            >
                <option :value="null">Selecione a categoria</option>
                <option v-for="opcao in categoriasDisponiveis" :key="opcao.id" :value="opcao.id">{{ opcao.nome }}</option>
            </select>
        </CampoCadastro>
        <p v-else-if="cadastroEscolhido && categorias.length > 0" class="rounded-[12px] border border-hairline bg-nevoa px-4 py-3 text-[0.9375rem] leading-[1.5]">
            Nenhuma categoria deste edital é para {{ cadastroEscolhido.naturezaRotulo }}. A inscrição segue sem categoria.
        </p>

        <fieldset v-if="cotas.length > 0" class="flex min-w-0 flex-col gap-3" :aria-describedby="erros.cotas ? 'inscricao-cotas-erro' : undefined">
            <legend class="text-[0.9375rem] font-semibold">Concorrer por cota</legend>
            <p class="text-[0.8125rem] leading-[1.5] text-tinta-suave">
                Marque só se for concorrer por cota; ela pode pedir documentos a mais.
                <template v-if="vedaNegroIndigena">O edital não permite marcar pessoas negras e indígenas ao mesmo tempo.</template>
            </p>
            <div class="flex flex-col gap-1 rounded-[12px] border border-hairline px-4 py-2">
                <Interruptor
                    v-for="cota in cotas"
                    :id="`inscricao-cota-${cota.valor}`"
                    :key="cota.valor"
                    :model-value="cotasEscolhidas.includes(cota.valor)"
                    :rotulo="cota.rotulo"
                    variante="linha"
                    :desabilitado="cotaBloqueada(cota.valor)"
                    :invalido="Boolean(erros.cotas) && !cotaBloqueada(cota.valor)"
                    :descricao="erros.cotas ? 'inscricao-cotas-erro' : undefined"
                    @update:model-value="(ligado: boolean) => alternarCota(cota.valor, ligado)"
                />
            </div>
            <p v-if="erros.cotas" id="inscricao-cotas-erro" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ erros.cotas }}</p>
        </fieldset>
    </div>
</template>
