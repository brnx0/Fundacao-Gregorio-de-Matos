<script setup lang="ts">
import { computed } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import ListaEditavel from './ListaEditavel.vue';
import { OPCOES_SIM_NAO, classeCaixa, classeCampo, classeDica, classeMarcacao, classeTexto, decimal, novaChave } from '../listaEditavel';
import type { CategoriaEditavel, CriterioEditavel, GrupoPontuacaoEditavel, PontuacaoExtraEditavel } from '../../../types';

const props = defineProps<{
    /** Erros do useForm: "criterios.N.campo" e "pontuacao_extra.*". */
    erros: Partial<Record<string, string>>;
    /** Categorias atuais da aba Categorias, na ordem em que estão. */
    categorias: CategoriaEditavel[];
    /** Edital com nota dada: nenhum critério entra ou sai, e os salvos não mudam peso, pontos nem categorias. */
    travados: boolean;
    /** Motivo da trava, mostrado acima da lista. */
    avisoTravados: string;
}>();

const emit = defineEmits<{ limparErros: [campos: string[]] }>();

const criterios = defineModel<CriterioEditavel[]>({ required: true });
const pontuacao = defineModel<PontuacaoExtraEditavel>('pontuacao', { required: true });

// O aviso da trava já aparece acima da lista; a lista só mostra outro erro geral.
const errosDaLista = computed<Partial<Record<string, string>>>(() => (props.travados && props.erros.criterios === props.avisoTravados ? { ...props.erros, criterios: undefined } : props.erros));

/** Critério já salvo num edital travado: peso, pontos e categorias só para ler. */
const fixo = (criterio: CriterioEditavel): boolean => props.travados && criterio.id != null;

const CAMPOS_VISIVEIS = /^(nome|descricao|peso|pontos_maximos|todas_categorias|categorias)(\.|$)/;

const rotuloDaCategoria = (categoria: CategoriaEditavel, posicao: number): string => categoria.nome.trim() || `Categoria ${posicao + 1}`;

const resumo = (criterio: CriterioEditavel): string =>
    [criterio.peso ? `Peso ${criterio.peso}` : null, criterio.pontos_maximos ? `até ${criterio.pontos_maximos} pontos` : null].filter(Boolean).join(' · ') || 'Peso e pontos não informados';

const escolherTodas = (criterio: CriterioEditavel, todas: boolean, limparLista: (campo: string) => void): void => {
    criterio.todas_categorias = todas;
    limparLista('categorias');
};

const novo = (): CriterioEditavel => ({ chave: novaChave(), id: null, nome: '', descricao: '', peso: '', pontos_maximos: '', todas_categorias: true, categorias: [] });

const novoGrupo = (): GrupoPontuacaoEditavel => ({ chave: novaChave(), grupo: '', pontos_por_pessoa: '', maximo: '' });

const limparPontuacao = (campo: string): void => emit('limparErros', [`pontuacao_extra.${campo}`]);
</script>

<template>
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-3">
            <h3 class="text-[1.125rem] font-bold">Critérios</h3>
            <div v-if="travados" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="11" width="14" height="10" rx="2" />
                    <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                </svg>
                <p class="text-[0.9375rem] leading-[1.6] font-semibold">{{ avisoTravados }}</p>
            </div>
            <ListaEditavel
                v-model="criterios"
                id="criterio"
                prefixo="criterios"
                :erros="errosDaLista"
                singular="critério"
                plural="critérios"
                vazio="Nenhum critério de avaliação cadastrado."
                :novo="novo"
                campo-inicial="nome"
                :nome="(criterio) => criterio.nome"
                :resumo="resumo"
                :conta-no-selo="(campo) => CAMPOS_VISIVEIS.test(campo)"
                :nivel="4"
                :remover-so-novos="travados"
                :sem-adicionar="travados"
                @limpar-erros="emit('limparErros', $event)"
            >
                <template #default="{ item: criterio, idCampo, erro, erroDaLista, limpar, limparLista }">
                    <CampoCadastro :id="idCampo('nome')" rotulo="Nome" obrigatorio :erro="erro('nome')" v-slot="{ descricao, invalido }">
                        <input
                            :id="idCampo('nome')"
                            v-model="criterio.nome"
                            type="text"
                            maxlength="500"
                            required
                            placeholder="Ex.: Relevância cultural do projeto"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('nome')"
                        />
                    </CampoCadastro>

                    <CampoCadastro :id="idCampo('descricao')" rotulo="Descrição" :erro="erro('descricao')" v-slot="{ descricao, invalido }">
                        <textarea
                            :id="idCampo('descricao')"
                            v-model="criterio.descricao"
                            rows="2"
                            maxlength="2000"
                            :class="classeTexto"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('descricao')"
                        />
                    </CampoCadastro>

                    <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                        <CampoCadastro :id="idCampo('peso')" rotulo="Peso" :erro="erro('peso')" v-slot="{ descricao, invalido }">
                            <input
                                :id="idCampo('peso')"
                                :value="criterio.peso"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :readonly="fixo(criterio)"
                                :class="[classeCampo, 'tabular-nums read-only:bg-nevoa']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="
                                    criterio.peso = decimal($event);
                                    limpar('peso');
                                "
                            />
                        </CampoCadastro>

                        <CampoCadastro :id="idCampo('pontos_maximos')" rotulo="Pontos máximos" :erro="erro('pontos_maximos')" v-slot="{ descricao, invalido }">
                            <input
                                :id="idCampo('pontos_maximos')"
                                :value="criterio.pontos_maximos"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :readonly="fixo(criterio)"
                                :class="[classeCampo, 'tabular-nums read-only:bg-nevoa']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="
                                    criterio.pontos_maximos = decimal($event);
                                    limpar('pontos_maximos');
                                "
                            />
                        </CampoCadastro>
                    </div>

                    <fieldset class="min-w-0" :aria-describedby="erroDaLista('categorias') ? idCampo('categorias-erro') : undefined">
                        <legend class="text-[0.9375rem] font-semibold">
                            Categorias<span v-if="!criterio.todas_categorias" aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                        </legend>
                        <div class="mt-1.5 flex flex-wrap gap-x-6">
                            <label :class="classeMarcacao">
                                <input
                                    type="radio"
                                    :name="idCampo('todas')"
                                    :checked="criterio.todas_categorias"
                                    :disabled="fixo(criterio)"
                                    :class="classeCaixa"
                                    @change="escolherTodas(criterio, true, limparLista)"
                                />
                                Todas as categorias
                            </label>
                            <label :class="classeMarcacao">
                                <input
                                    type="radio"
                                    :name="idCampo('todas')"
                                    :checked="!criterio.todas_categorias"
                                    :disabled="fixo(criterio)"
                                    :class="classeCaixa"
                                    @change="escolherTodas(criterio, false, limparLista)"
                                />
                                Só algumas
                            </label>
                        </div>
                        <div v-if="!criterio.todas_categorias" class="mt-1 grid gap-x-6 border-l-2 border-hairline pl-4 @lg:grid-cols-2">
                            <p v-if="categorias.length === 0" :class="[classeDica, 'py-2']">Cadastre as categorias na aba Categorias.</p>
                            <label v-for="(categoria, posicao) in categorias" :key="categoria.chave" :class="classeMarcacao">
                                <input
                                    v-model="criterio.categorias"
                                    type="checkbox"
                                    :value="categoria.chave"
                                    :required="criterio.categorias.length === 0"
                                    :disabled="fixo(criterio)"
                                    :class="classeCaixa"
                                    :aria-invalid="erroDaLista('categorias') ? true : undefined"
                                    @change="limparLista('categorias')"
                                />
                                {{ rotuloDaCategoria(categoria, posicao) }}
                            </label>
                        </div>
                        <p v-if="erroDaLista('categorias')" :id="idCampo('categorias-erro')" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">
                            {{ erroDaLista('categorias') }}
                        </p>
                    </fieldset>
                </template>
            </ListaEditavel>
        </div>

        <div class="flex flex-col gap-3 border-t border-hairline pt-6">
            <div>
                <h3 class="text-[1.125rem] font-bold">Pontuação extra</h3>
                <p :class="classeDica">Pontos a mais por pessoas de grupos específicos na equipe (ex.: mulheres, pessoas LGBTQIAPN+).</p>
            </div>

            <ListaEditavel
                v-model="pontuacao.grupos"
                id="grupo"
                prefixo="pontuacao_extra.grupos"
                :erros="erros"
                singular="grupo"
                plural="grupos"
                vazio="Nenhum grupo com pontuação extra."
                :novo="novoGrupo"
                campo-inicial="grupo"
                :nome="(grupo) => grupo.grupo"
                :resumo="(grupo) => [grupo.pontos_por_pessoa && `${grupo.pontos_por_pessoa} por pessoa`, grupo.maximo && `máximo ${grupo.maximo}`].filter(Boolean).join(' · ') || 'Pontos não informados'"
                :nivel="4"
                @limpar-erros="emit('limparErros', $event)"
            >
                <template #default="{ item: grupo, idCampo, erro, limpar }">
                    <CampoCadastro :id="idCampo('grupo')" rotulo="Grupo" obrigatorio :erro="erro('grupo')" v-slot="{ descricao, invalido }">
                        <input
                            :id="idCampo('grupo')"
                            v-model="grupo.grupo"
                            type="text"
                            maxlength="255"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('grupo')"
                        />
                    </CampoCadastro>

                    <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                        <CampoCadastro :id="idCampo('pontos_por_pessoa')" rotulo="Pontos por pessoa" :erro="erro('pontos_por_pessoa')" v-slot="{ descricao, invalido }">
                            <input
                                :id="idCampo('pontos_por_pessoa')"
                                :value="grupo.pontos_por_pessoa"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :class="[classeCampo, 'tabular-nums']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="
                                    grupo.pontos_por_pessoa = decimal($event);
                                    limpar('pontos_por_pessoa');
                                "
                            />
                        </CampoCadastro>

                        <CampoCadastro :id="idCampo('maximo')" rotulo="Máximo do grupo" :erro="erro('maximo')" v-slot="{ descricao, invalido }">
                            <input
                                :id="idCampo('maximo')"
                                :value="grupo.maximo"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :class="[classeCampo, 'tabular-nums']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="
                                    grupo.maximo = decimal($event);
                                    limpar('maximo');
                                "
                            />
                        </CampoCadastro>
                    </div>
                </template>
            </ListaEditavel>

            <div class="mt-2 grid gap-x-6 gap-y-5 md:grid-cols-2">
                <CampoCadastro id="pontuacao_teto" rotulo="Teto total da pontuação extra" :erro="erros['pontuacao_extra.teto']" v-slot="{ descricao, invalido }">
                    <input
                        id="pontuacao_teto"
                        :value="pontuacao.teto"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :class="[classeCampo, 'tabular-nums']"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="
                            pontuacao.teto = decimal($event);
                            limparPontuacao('teto');
                        "
                    />
                </CampoCadastro>

                <CampoCadastro
                    id="pontuacao_conta_para_corte"
                    rotulo="Conta para atingir a nota de corte"
                    :erro="erros['pontuacao_extra.conta_para_corte']"
                    v-slot="{ descricao, invalido }"
                >
                    <select
                        id="pontuacao_conta_para_corte"
                        v-model="pontuacao.conta_para_corte"
                        :class="classeCampo"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @change="limparPontuacao('conta_para_corte')"
                    >
                        <option v-for="opcao in OPCOES_SIM_NAO" :key="String(opcao.valor)" :value="opcao.valor">{{ opcao.rotulo }}</option>
                    </select>
                </CampoCadastro>
            </div>
        </div>
    </div>
</template>
