<script setup lang="ts">
import { computed } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import ListaEditavel from './ListaEditavel.vue';
import { classeCaixa, classeCampo, classeDica, classeMarcacao, classeTexto, inteiro, novaChave, reais, textoReais } from '../listaEditavel';
import type { CategoriaEditavel, CategoriaItemEditavel, ChamadaEditavel, OpcaoFormulario } from '../../../types';

const props = defineProps<{
    /** Erros do useForm; os das categorias vêm como "categorias.N.campo" e os dos itens como "categorias.N.itens.M.campo". */
    erros: Partial<Record<string, string>>;
    naturezas: OpcaoFormulario[];
    /** Chamadas atuais da aba Períodos, na ordem em que estão. */
    chamadas: ChamadaEditavel[];
    /** Edital com nota dada: categoria salva não pode ser removida. */
    travadas: boolean;
    /** Motivo da trava, mostrado acima da lista. */
    avisoTravadas: string;
}>();

const emit = defineEmits<{ limparErros: [campos: string[]] }>();

const categorias = defineModel<CategoriaEditavel[]>({ required: true });

// O aviso da trava já aparece acima da lista; a lista só mostra outro erro geral.
const errosDaLista = computed<Partial<Record<string, string>>>(() => (props.travadas && props.erros.categorias === props.avisoTravadas ? { ...props.erros, categorias: undefined } : props.erros));

/** Mesmos limites do SalvarEditalRequest. */
const MAXIMO_VAGAS = 1000000;

const vagas = (quantidade: number): string => `${quantidade} ${quantidade === 1 ? 'vaga' : 'vagas'}`;

const resumoValores = (valor: number | null, quantidade: number | null): string =>
    [valor === null ? null : textoReais(valor), quantidade === null ? null : vagas(quantidade)].filter(Boolean).join(' · ');

const resumo = (categoria: CategoriaEditavel): string => {
    const itens = categoria.itens.length > 0 ? `${categoria.itens.length} ${categoria.itens.length === 1 ? 'item' : 'itens'}` : null;

    return [resumoValores(categoria.valor, categoria.vagas) || null, itens].filter(Boolean).join(' · ') || 'Valor e vagas não informados';
};

const rotuloDaChamada = (chamada: ChamadaEditavel, posicao: number): string => chamada.nome.trim() || `Chamada ${posicao + 1}`;

const nova = (): CategoriaEditavel => ({
    chave: novaChave(),
    id: null,
    nome: '',
    descricao: '',
    valor: null,
    vagas: null,
    naturezas: [],
    chamada: null,
    itens: [],
});

const novoItem = (): CategoriaItemEditavel => ({ chave: novaChave(), id: null, nome: '', valor: null, vagas: null, suplentes: null });
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-if="travadas" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="11" width="14" height="10" rx="2" />
                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
            </svg>
            <p class="text-[0.9375rem] leading-[1.6] font-semibold">{{ avisoTravadas }}</p>
        </div>

        <ListaEditavel
            v-model="categorias"
            id="categoria"
            prefixo="categorias"
            :erros="errosDaLista"
            singular="categoria"
            plural="categorias"
            feminino
            vazio="Nenhuma categoria cadastrada. Para publicar, cadastre ao menos uma."
            :novo="nova"
            campo-inicial="nome"
            :nome="(categoria) => categoria.nome"
            :resumo="resumo"
            :remover-so-novos="travadas"
            @limpar-erros="emit('limparErros', $event)"
        >
            <template #default="{ item: categoria, indice, idCampo, erro, erroDaLista, limpar, limparLista }">
                <CampoCadastro :id="idCampo('nome')" rotulo="Nome" obrigatorio :erro="erro('nome')" v-slot="{ descricao, invalido }">
                    <input
                        :id="idCampo('nome')"
                        v-model="categoria.nome"
                        type="text"
                        maxlength="255"
                        required
                        placeholder="Ex.: Produção de curta-metragem"
                        :class="classeCampo"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="limpar('nome')"
                    />
                </CampoCadastro>

                <CampoCadastro :id="idCampo('descricao')" rotulo="Descrição" :erro="erro('descricao')" v-slot="{ descricao, invalido }">
                    <textarea
                        :id="idCampo('descricao')"
                        v-model="categoria.descricao"
                        rows="3"
                        maxlength="2000"
                        :class="classeTexto"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="limpar('descricao')"
                    />
                </CampoCadastro>

                <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                    <CampoCadastro
                        :id="idCampo('valor')"
                        rotulo="Valor por proposta"
                        ajuda="Deixe vazio quando o edital usa faixa ou valor variável."
                        :erro="erro('valor')"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo('valor')"
                            :value="textoReais(categoria.valor)"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="R$ 0,00"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="
                                categoria.valor = reais($event);
                                limpar('valor');
                            "
                        />
                    </CampoCadastro>

                    <CampoCadastro :id="idCampo('vagas')" rotulo="Vagas" ajuda="Deixe vazio quando o edital não define." :erro="erro('vagas')" v-slot="{ descricao, invalido }">
                        <input
                            :id="idCampo('vagas')"
                            :value="categoria.vagas ?? ''"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="
                                categoria.vagas = inteiro($event, MAXIMO_VAGAS);
                                limpar('vagas');
                            "
                        />
                    </CampoCadastro>
                </div>

                <CampoCadastro
                    v-if="chamadas.length > 1"
                    :id="idCampo('chamada')"
                    rotulo="Chamada"
                    ajuda="Em qual chamada a categoria é oferecida."
                    :erro="erro('chamada')"
                    v-slot="{ descricao, invalido }"
                >
                    <select
                        :id="idCampo('chamada')"
                        v-model="categoria.chamada"
                        :class="classeCampo"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @change="limpar('chamada')"
                    >
                        <option :value="null">Todas as chamadas</option>
                        <option v-for="(chamada, posicao) in chamadas" :key="chamada.chave" :value="chamada.chave">{{ rotuloDaChamada(chamada, posicao) }}</option>
                    </select>
                </CampoCadastro>

                <fieldset class="min-w-0" :aria-describedby="[idCampo('naturezas-ajuda'), erroDaLista('naturezas') && idCampo('naturezas-erro')].filter(Boolean).join(' ')">
                    <legend class="text-[0.9375rem] font-semibold">Quem pode concorrer nesta categoria</legend>
                    <p :id="idCampo('naturezas-ajuda')" :class="[classeDica, 'mt-1']">Nenhuma marcada = as mesmas naturezas do edital.</p>
                    <div class="mt-1.5 grid gap-x-6 @lg:grid-cols-2">
                        <label v-for="natureza in naturezas" :key="natureza.valor" :class="classeMarcacao">
                            <input
                                v-model="categoria.naturezas"
                                type="checkbox"
                                :value="natureza.valor"
                                :class="classeCaixa"
                                :aria-invalid="erroDaLista('naturezas') ? true : undefined"
                                @change="limparLista('naturezas')"
                            />
                            {{ natureza.rotulo }}
                        </label>
                    </div>
                    <p v-if="erroDaLista('naturezas')" :id="idCampo('naturezas-erro')" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">{{ erroDaLista('naturezas') }}</p>
                </fieldset>

                <div class="flex min-w-0 flex-col gap-3 border-t border-hairline pt-5">
                    <div>
                        <p class="text-[0.9375rem] font-semibold">Itens da categoria</p>
                        <p :class="classeDica">Opcional. Para editais em que o proponente escolhe um item dentro da categoria (um bem, um espaço).</p>
                    </div>

                    <ListaEditavel
                        v-model="categoria.itens"
                        :id="`${idCampo('item')}`"
                        :prefixo="`categorias.${indice}.itens`"
                        :erros="erros"
                        singular="item"
                        plural="itens"
                        vazio="Nenhum item."
                        :novo="novoItem"
                        campo-inicial="nome"
                        :nome="(item) => item.nome"
                        :resumo="(item) => resumoValores(item.valor, item.vagas) || 'Valor e vagas não informados'"
                        :nivel="4"
                        @limpar-erros="emit('limparErros', $event)"
                    >
                        <template #default="{ item, idCampo: idDoItem, erro: erroDoItem, limpar: limparItem }">
                            <CampoCadastro :id="idDoItem('nome')" rotulo="Nome do item" obrigatorio :erro="erroDoItem('nome')" v-slot="{ descricao, invalido }">
                                <input
                                    :id="idDoItem('nome')"
                                    v-model="item.nome"
                                    type="text"
                                    maxlength="255"
                                    required
                                    :class="classeCampo"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @input="limparItem('nome')"
                                />
                            </CampoCadastro>

                            <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-3">
                                <CampoCadastro :id="idDoItem('valor')" rotulo="Valor" :erro="erroDoItem('valor')" v-slot="{ descricao, invalido }">
                                    <input
                                        :id="idDoItem('valor')"
                                        :value="textoReais(item.valor)"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        placeholder="R$ 0,00"
                                        :class="[classeCampo, 'tabular-nums']"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            item.valor = reais($event);
                                            limparItem('valor');
                                        "
                                    />
                                </CampoCadastro>

                                <CampoCadastro :id="idDoItem('vagas')" rotulo="Vagas" :erro="erroDoItem('vagas')" v-slot="{ descricao, invalido }">
                                    <input
                                        :id="idDoItem('vagas')"
                                        :value="item.vagas ?? ''"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        :class="[classeCampo, 'tabular-nums']"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            item.vagas = inteiro($event, MAXIMO_VAGAS);
                                            limparItem('vagas');
                                        "
                                    />
                                </CampoCadastro>

                                <CampoCadastro :id="idDoItem('suplentes')" rotulo="Suplentes" :erro="erroDoItem('suplentes')" v-slot="{ descricao, invalido }">
                                    <input
                                        :id="idDoItem('suplentes')"
                                        :value="item.suplentes ?? ''"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        :class="[classeCampo, 'tabular-nums']"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            item.suplentes = inteiro($event, MAXIMO_VAGAS, true);
                                            limparItem('suplentes');
                                        "
                                    />
                                </CampoCadastro>
                            </div>
                        </template>
                    </ListaEditavel>
                </div>
            </template>
        </ListaEditavel>
    </div>
</template>
