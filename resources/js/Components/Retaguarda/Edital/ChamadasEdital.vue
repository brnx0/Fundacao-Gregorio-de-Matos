<script setup lang="ts">
import CampoCadastro from '../../CampoCadastro.vue';
import ListaEditavel from './ListaEditavel.vue';
import { classeCampo, classeDica, novaChave } from '../listaEditavel';
import type { ChamadaEditavel } from '../../../types';

defineProps<{
    /** Erros do useForm; os das chamadas vêm como "chamadas.N.campo". */
    erros: Partial<Record<string, string>>;
}>();

const emit = defineEmits<{ limparErros: [campos: string[]] }>();

const chamadas = defineModel<ChamadaEditavel[]>({ required: true });

const data = (texto: string): string => (texto ? texto.split('-').reverse().join('/') : '');

const resumo = (chamada: ChamadaEditavel): string =>
    chamada.inicio || chamada.fim ? `${data(chamada.inicio) || '…'} a ${data(chamada.fim) || '…'}` : 'Datas a preencher';

const nova = (): ChamadaEditavel => ({ chave: novaChave(), id: null, nome: `Chamada ${chamadas.value.length + 1}`, inicio: '', fim: '', homologacao_inicio: '', homologacao_fim: '', validacao_inicio: '', validacao_fim: '' });
</script>

<template>
    <ListaEditavel
        v-model="chamadas"
        id="chamada"
        prefixo="chamadas"
        :erros="erros"
        singular="chamada"
        plural="chamadas"
        feminino
        vazio="Nenhuma chamada cadastrada. Para publicar, cadastre ao menos uma com início e fim das inscrições."
        :novo="nova"
        campo-inicial="nome"
        :nome="(chamada) => chamada.nome"
        :resumo="resumo"
        @limpar-erros="emit('limparErros', $event)"
    >
        <template #default="{ item: chamada, idCampo, erro, limpar }">
            <CampoCadastro :id="idCampo('nome')" rotulo="Nome" obrigatorio :erro="erro('nome')" v-slot="{ descricao, invalido }">
                <input
                    :id="idCampo('nome')"
                    v-model="chamada.nome"
                    type="text"
                    maxlength="100"
                    required
                    placeholder="Ex.: Chamada 1"
                    :class="classeCampo"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @input="limpar('nome')"
                />
            </CampoCadastro>

            <fieldset class="min-w-0">
                <legend class="text-[0.9375rem] font-semibold">Inscrição</legend>
                <div class="mt-3 grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                    <CampoCadastro :id="idCampo('inicio')" rotulo="Início" obrigatorio :erro="erro('inicio')" v-slot="{ descricao, invalido }">
                        <input
                            :id="idCampo('inicio')"
                            v-model="chamada.inicio"
                            type="date"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('inicio', 'fim')"
                        />
                    </CampoCadastro>

                    <CampoCadastro :id="idCampo('fim')" rotulo="Fim" obrigatorio :erro="erro('fim')" v-slot="{ descricao, invalido }">
                        <input
                            :id="idCampo('fim')"
                            v-model="chamada.fim"
                            type="date"
                            required
                            :min="chamada.inicio || undefined"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('fim')"
                        />
                    </CampoCadastro>
                </div>
            </fieldset>

            <fieldset class="min-w-0" :aria-describedby="idCampo('homologacao-ajuda')">
                <legend class="text-[0.9375rem] font-semibold">Homologação</legend>
                <p :id="idCampo('homologacao-ajuda')" :class="[classeDica, 'mt-1']">Opcional. Preencha as duas datas, ou nenhuma, quando o edital trouxer o período; com uma delas preenchida, a outra passa a ser obrigatória.</p>
                <div class="mt-3 grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                    <CampoCadastro
                        :id="idCampo('homologacao_inicio')"
                        rotulo="Início"
                        :obrigatorio="Boolean(chamada.homologacao_fim)"
                        :erro="erro('homologacao_inicio')"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo('homologacao_inicio')"
                            v-model="chamada.homologacao_inicio"
                            type="date"
                            :required="Boolean(chamada.homologacao_fim)"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('homologacao_inicio', 'homologacao_fim')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        :id="idCampo('homologacao_fim')"
                        rotulo="Fim"
                        :obrigatorio="Boolean(chamada.homologacao_inicio)"
                        :erro="erro('homologacao_fim')"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo('homologacao_fim')"
                            v-model="chamada.homologacao_fim"
                            type="date"
                            :required="Boolean(chamada.homologacao_inicio)"
                            :min="chamada.homologacao_inicio || undefined"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('homologacao_fim', 'homologacao_inicio')"
                        />
                    </CampoCadastro>
                </div>
            </fieldset>

            <fieldset class="min-w-0" :aria-describedby="idCampo('avaliacao-ajuda')">
                <legend class="text-[0.9375rem] font-semibold">Avaliação</legend>
                <p :id="idCampo('avaliacao-ajuda')" :class="[classeDica, 'mt-1']">Opcional. Preencha as duas datas, ou nenhuma, quando o edital trouxer o período; com uma delas preenchida, a outra passa a ser obrigatória.</p>
                <div class="mt-3 grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                    <CampoCadastro
                        :id="idCampo('validacao_inicio')"
                        rotulo="Início"
                        :obrigatorio="Boolean(chamada.validacao_fim)"
                        :erro="erro('validacao_inicio')"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo('validacao_inicio')"
                            v-model="chamada.validacao_inicio"
                            type="date"
                            :required="Boolean(chamada.validacao_fim)"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('validacao_inicio', 'validacao_fim')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        :id="idCampo('validacao_fim')"
                        rotulo="Fim"
                        :obrigatorio="Boolean(chamada.validacao_inicio)"
                        :erro="erro('validacao_fim')"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo('validacao_fim')"
                            v-model="chamada.validacao_fim"
                            type="date"
                            :required="Boolean(chamada.validacao_inicio)"
                            :min="chamada.validacao_inicio || undefined"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('validacao_fim', 'validacao_inicio')"
                        />
                    </CampoCadastro>
                </div>
            </fieldset>
        </template>
    </ListaEditavel>
</template>
