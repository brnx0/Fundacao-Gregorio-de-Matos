<script setup lang="ts">
import { computed } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import { useForm } from '@inertiajs/vue3';
import type { AvaliadorParaTransferir } from '../../../types';

const props = defineProps<{
    /** Slug do edital. */
    edital: string;
    /** Prefixo dos ids dos campos (a página pode ter mais de um formulário). */
    id: string;
    /** Para quem as inscrições podem ir: os avaliadores ativos do edital. */
    destinos: AvaliadorParaTransferir[];
    /** De quem podem sair; sem a origem fixa, escolhida na lista. */
    origens?: AvaliadorParaTransferir[];
    /** Origem fixa (o atalho do aviso do desativado): só se escolhe o destino. */
    de?: AvaliadorParaTransferir;
    /** Campos um embaixo do outro (célula estreita de tabela). */
    empilhado?: boolean;
}>();

// Caminho da rota retaguarda.avaliacao.transferencia; o front não tem helper de rotas nomeadas.
const URL = `/retaguarda/avaliacao/editais/${props.edital}/transferencia`;

const form = useForm<{ de: number | ''; para: number | '' }>({ de: props.de?.avaliadorId ?? '', para: '' });

const paraEscolher = computed<AvaliadorParaTransferir[]>(() => props.destinos.filter((destino) => destino.avaliadorId !== form.de));

const transferir = (): void => {
    form.post(URL, {
        preserveScroll: true,
        onSuccess: () => form.reset('para'),
    });
};
</script>

<template>
    <form novalidate class="flex flex-col gap-3" :aria-label="de ? `Transferir as inscrições não concluídas de ${de.nome}` : 'Transferir as inscrições não concluídas'" @submit.prevent="transferir">
        <div class="flex flex-col gap-3" :class="{ 'sm:flex-row sm:items-start': !empilhado }">
            <CampoCadastro v-if="!de" :id="`${id}-de`" rotulo="De" obrigatorio :erro="form.errors.de" v-slot="{ descricao, invalido }">
                <select
                    :id="`${id}-de`"
                    v-model="form.de"
                    class="campo-retaguarda"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="form.clearErrors('de', 'para')"
                >
                    <option value="" disabled>Escolha o avaliador</option>
                    <option v-for="origem in origens ?? []" :key="origem.avaliadorId" :value="origem.avaliadorId">{{ origem.nome }}</option>
                </select>
            </CampoCadastro>

            <CampoCadastro :id="`${id}-para`" :rotulo="de ? 'Transferir para' : 'Para'" obrigatorio :erro="form.errors.para" v-slot="{ descricao, invalido }">
                <select
                    :id="`${id}-para`"
                    v-model="form.para"
                    class="campo-retaguarda"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="form.clearErrors('para')"
                >
                    <option value="" disabled>Escolha o avaliador</option>
                    <option v-for="destino in paraEscolher" :key="destino.avaliadorId" :value="destino.avaliadorId">{{ destino.nome }}</option>
                </select>
            </CampoCadastro>

            <button type="submit" :disabled="form.processing" class="botao-primario shrink-0 disabled:cursor-not-allowed disabled:opacity-50" :class="{ 'sm:mt-7': !empilhado }">
                {{ form.processing ? 'Transferindo…' : 'Transferir' }}
            </button>
        </div>
        <p v-if="de && form.errors.de" role="alert" class="text-[0.875rem] font-semibold text-invalido-texto">{{ form.errors.de }}</p>
        <p v-if="destinos.length === 0" class="text-[0.8125rem] font-semibold">Não há avaliador ativo no edital para receber as inscrições.</p>
        <p class="text-[0.8125rem] leading-[1.5] text-tinta-suave">Só as não concluídas mudam de avaliador, e as notas e observações já lançadas nelas são apagadas.</p>
    </form>
</template>
