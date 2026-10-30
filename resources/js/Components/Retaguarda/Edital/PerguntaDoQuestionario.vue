<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import Interruptor from '../../Interruptor.vue';
import VinculoDaPergunta from './VinculoDaPergunta.vue';
import IconeAcao from '../IconeAcao.vue';
import { MAXIMO_ITENS, classeCampo, classeCampoBase, classeDica, classeTexto, inteiro } from '../listaEditavel';
import { LIMITE_MAXIMO_CARACTERES, TIPO_DECLARACAO, nomeDaPergunta, novaChaveDeOpcao, perguntasEmOrdem, regraDoTipo, respostasDaOrigem } from '../questionario';
import type { CategoriaDoQuestionario, OpcaoFormulario, PerguntaEditavel, SecaoQuestionarioEditavel } from '../../../types';

/** Campos de uma pergunta do questionário, dentro do cartão da ListaEditavel. */
const props = defineProps<{
    /** Todas as seções do questionário (ordem global das perguntas e destino de "Mover para a seção"). */
    secoes: SecaoQuestionarioEditavel[];
    /** Chave da seção em que a pergunta está. */
    secao: string;
    categorias: CategoriaDoQuestionario[];
    tiposPergunta: OpcaoFormulario[];
    /** Do escopo do cartão (ListaEditavel). */
    idCampo: (campo: string) => string;
    erro: (campo: string) => string | undefined;
    erroDaLista: (campo: string) => string | undefined;
    limpar: (...campos: string[]) => void;
    limparLista: (campo: string) => void;
    /** Questionário travado: tudo fica só para leitura. */
    desabilitado?: boolean;
}>();

const emit = defineEmits<{
    /** Leva a pergunta para o fim da seção escolhida. */
    mover: [secao: string];
}>();

const pergunta = defineModel<PerguntaEditavel>({ required: true });

/** Mesmo limite de RegrasDoQuestionario. */
const MAXIMO_OPCOES = 50;

const regra = computed(() => regraDoTipo(pergunta.value.tipo));
const declaracao = computed<boolean>(() => pergunta.value.tipo === TIPO_DECLARACAO);

// Opções e limite ficam guardados ao trocar de tipo (voltar não perde nada); o envio só manda os que valem.
const trocarTipo = (): void => {
    if (declaracao.value) pergunta.value.obrigatoria = true;
    if (regra.value.temOpcoes && pergunta.value.opcoes.length === 0) {
        pergunta.value.opcoes = [
            { chave: novaChaveDeOpcao(), texto: '', outro: false },
            { chave: novaChaveDeOpcao(), texto: '', outro: false },
        ];
    }
    props.limpar('tipo', 'limite_caracteres', 'obrigatoria');
    props.limparLista('opcoes');
};

// Opções: subir/descer/remover mudam os índices, então os erros delas saem.
const anuncioOpcoes = ref<string>('');
const botaoAdicionarOpcao = ref<HTMLButtonElement | null>(null);

const focar = async (id: string): Promise<void> => {
    await nextTick();
    document.getElementById(id)?.focus();
};

const adicionarOpcao = (): void => {
    const nova = { chave: novaChaveDeOpcao(), texto: '', outro: false };
    pergunta.value.opcoes = [...pergunta.value.opcoes, nova];
    props.limparLista('opcoes');
    void focar(props.idCampo(`opcao-${nova.chave}`));
};

const moverOpcao = (indice: number, direcao: -1 | 1): void => {
    const destino = indice + direcao;
    const opcoes = [...pergunta.value.opcoes];
    const movida = opcoes[indice];
    if (!movida || destino < 0 || destino >= opcoes.length) return;

    opcoes.splice(indice, 1);
    opcoes.splice(destino, 0, movida);
    pergunta.value.opcoes = opcoes;
    props.limparLista('opcoes');
    anuncioOpcoes.value = `Opção movida para a posição ${destino + 1} de ${opcoes.length}.`;

    const nasPontas = destino === 0 || destino === opcoes.length - 1;
    void focar(props.idCampo(nasPontas ? `opcao-${movida.chave}` : `opcao-${movida.chave}-${direcao === -1 ? 'subir' : 'descer'}`));
};

const removerOpcao = (indice: number): void => {
    const opcoes = pergunta.value.opcoes.filter((_, posicao) => posicao !== indice);
    pergunta.value.opcoes = opcoes;
    props.limparLista('opcoes');
    anuncioOpcoes.value = 'Opção removida.';

    const vizinha = opcoes[indice - 1] ?? opcoes[indice];
    if (vizinha) void focar(props.idCampo(`opcao-${vizinha.chave}`));
    else void nextTick(() => botaoAdicionarOpcao.value?.focus());
};

// Só uma opção pode ser "Outro": ligar numa desliga nas outras.
const marcarOutro = (indice: number, ligado: boolean): void => {
    pergunta.value.opcoes.forEach((opcao, posicao) => {
        opcao.outro = posicao === indice ? ligado : ligado ? false : opcao.outro;
    });
    props.limparLista('opcoes');
};

// Condição: só perguntas anteriores (na ordem do questionário) que podem ser origem.
const todas = computed<PerguntaEditavel[]>(() => perguntasEmOrdem(props.secoes));
const posicao = computed<number>(() => todas.value.findIndex((outra) => outra.chave === pergunta.value.chave));
const origem = computed<PerguntaEditavel | undefined>(() => todas.value.find((outra) => outra.chave === pergunta.value.condicao_pergunta));
const origemDepois = computed<boolean>(() => origem.value !== undefined && todas.value.indexOf(origem.value) > posicao.value);

const origensPossiveis = computed<PerguntaEditavel[]>(() => {
    const anteriores = todas.value.slice(0, Math.max(posicao.value, 0)).filter((outra) => regraDoTipo(outra.tipo).podeSerOrigem);

    // A origem atual que ficou depois desta continua na lista para o select mostrá-la (com o aviso).
    return origem.value && !anteriores.includes(origem.value) ? [...anteriores, origem.value] : anteriores;
});

const rotuloDaOrigem = (outra: PerguntaEditavel): string => `${todas.value.indexOf(outra) + 1}. ${nomeDaPergunta(outra)}`;

const escolherOrigem = (evento: Event): void => {
    const valor = (evento.target as HTMLSelectElement).value;
    pergunta.value.condicao_pergunta = valor === '' ? null : valor;
    pergunta.value.condicao_opcoes = [];
    props.limpar('condicao_pergunta');
    props.limparLista('condicao_opcoes');
};

const limparVinculo = (): void => {
    props.limparLista('categorias');
    props.limpar('todas_categorias');
};

const marcarResposta = (chave: string, marcada: boolean): void => {
    const sem = pergunta.value.condicao_opcoes.filter((outra) => outra !== chave);
    pergunta.value.condicao_opcoes = marcada ? [...sem, chave] : sem;
    props.limparLista('condicao_opcoes');
};

// Mover para outra seção: escolhe no select e confirma no botão (o select sozinho mudaria ao navegar pelas setas).
const outrasSecoes = computed(() =>
    props.secoes.map((secao, indice) => ({ secao, indice })).filter(({ secao }) => secao.chave !== props.secao),
);
const destino = ref<string>('');
const rotuloDaSecao = (secao: SecaoQuestionarioEditavel, indice: number): string => `Seção ${indice + 1} · ${secao.titulo.trim() || 'Sem título'}`;
const secaoCheia = (chave: string): boolean => (props.secoes.find((secao) => secao.chave === chave)?.perguntas.length ?? 0) >= MAXIMO_ITENS;

const mover = (): void => {
    if (destino.value === '' || secaoCheia(destino.value)) return;
    emit('mover', destino.value);
    destino.value = '';
};
</script>

<template>
    <!-- Travado, o fieldset desabilita todos os campos; o cartão continua abrindo para leitura. -->
    <fieldset :disabled="desabilitado" class="flex min-w-0 flex-col gap-5">
        <CampoCadastro :id="idCampo('enunciado')" rotulo="Enunciado" obrigatorio :erro="erro('enunciado')" v-slot="{ descricao, invalido }">
            <textarea
                :id="idCampo('enunciado')"
                v-model="pergunta.enunciado"
                rows="2"
                maxlength="2000"
                required
                :class="classeTexto"
                :aria-invalid="invalido || undefined"
                :aria-describedby="descricao"
                @input="limpar('enunciado')"
            />
        </CampoCadastro>

        <CampoCadastro :id="idCampo('ajuda')" rotulo="Texto de ajuda" ajuda="Explicação curta mostrada abaixo da pergunta." :erro="erro('ajuda')" v-slot="{ descricao, invalido }">
            <textarea
                :id="idCampo('ajuda')"
                v-model="pergunta.ajuda"
                rows="2"
                maxlength="2000"
                :class="classeTexto"
                :aria-invalid="invalido || undefined"
                :aria-describedby="descricao"
                @input="limpar('ajuda')"
            />
        </CampoCadastro>

        <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-2">
            <CampoCadastro :id="idCampo('tipo')" rotulo="Tipo" obrigatorio :erro="erro('tipo')" v-slot="{ descricao, invalido }">
                <select
                    :id="idCampo('tipo')"
                    v-model="pergunta.tipo"
                    required
                    :class="classeCampo"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="trocarTipo"
                >
                    <option v-for="tipo in tiposPergunta" :key="tipo.valor" :value="tipo.valor">{{ tipo.rotulo }}</option>
                </select>
            </CampoCadastro>

            <CampoCadastro
                v-if="regra.temLimite"
                :id="idCampo('limite_caracteres')"
                rotulo="Limite de caracteres"
                :ajuda="`Vazio = ${(regra.limitePadrao ?? 0).toLocaleString('pt-BR')} caracteres (o padrão).`"
                :erro="erro('limite_caracteres')"
                v-slot="{ descricao, invalido }"
            >
                <input
                    :id="idCampo('limite_caracteres')"
                    :value="pergunta.limite_caracteres ?? ''"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    :class="[classeCampo, 'tabular-nums']"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @input="
                        pergunta.limite_caracteres = inteiro($event, LIMITE_MAXIMO_CARACTERES);
                        limpar('limite_caracteres');
                    "
                />
            </CampoCadastro>
        </div>

        <Interruptor
            :id="idCampo('obrigatoria')"
            v-model="pergunta.obrigatoria"
            rotulo="Obrigatória"
            :ajuda="declaracao ? 'A declaração é sempre obrigatória.' : 'O proponente só finaliza a inscrição respondendo.'"
            :desabilitado="declaracao"
            :erro="erro('obrigatoria')"
            @change="limpar('obrigatoria')"
        />

        <fieldset
            v-if="regra.temOpcoes"
            class="min-w-0"
            :aria-describedby="[idCampo('opcoes-ajuda'), erro('opcoes') && idCampo('opcoes-erro')].filter(Boolean).join(' ')"
        >
            <legend class="text-[0.9375rem] font-semibold">Opções<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
            <p :id="idCampo('opcoes-ajuda')" :class="[classeDica, 'mt-1']">Ao menos duas. Só uma pode ser a opção "Outro".</p>

            <ol class="mt-3 flex flex-col gap-3">
                <li v-for="(opcao, indiceOpcao) in pergunta.opcoes" :key="opcao.chave" class="flex flex-col gap-2 rounded-[16px] border border-hairline p-3">
                    <CampoCadastro
                        :id="idCampo(`opcao-${opcao.chave}`)"
                        :rotulo="`Opção ${indiceOpcao + 1}`"
                        obrigatorio
                        :erro="erro(`opcoes.${indiceOpcao}.texto`) ?? erro(`opcoes.${indiceOpcao}.chave`)"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            :id="idCampo(`opcao-${opcao.chave}`)"
                            v-model="opcao.texto"
                            type="text"
                            maxlength="500"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || Boolean(erro('opcoes')) || undefined"
                            :aria-describedby="[descricao, erro('opcoes') && idCampo('opcoes-erro')].filter(Boolean).join(' ') || undefined"
                            @input="limpar(`opcoes.${indiceOpcao}.texto`, `opcoes.${indiceOpcao}.chave`)"
                        />
                    </CampoCadastro>
                    <div class="flex flex-wrap items-center justify-between gap-x-4">
                        <Interruptor
                            :id="idCampo(`opcao-${opcao.chave}-outro`)"
                            rotulo="É a opção Outro (abre um campo Qual?)"
                            variante="linha"
                            :model-value="opcao.outro"
                            @update:model-value="marcarOutro(indiceOpcao, $event)"
                        />
                        <div class="-mx-3 flex flex-wrap items-center">
                            <button
                                :id="idCampo(`opcao-${opcao.chave}-subir`)"
                                type="button"
                                :disabled="indiceOpcao === 0"
                                class="acao-icone"
                                data-dica="Subir"
                                @click="moverOpcao(indiceOpcao, -1)"
                            >
                                <IconeAcao nome="subir" />
                                <span class="sr-only">Subir{{ ` opção ${indiceOpcao + 1}` }}</span>
                            </button>
                            <button
                                :id="idCampo(`opcao-${opcao.chave}-descer`)"
                                type="button"
                                :disabled="indiceOpcao === pergunta.opcoes.length - 1"
                                class="acao-icone"
                                data-dica="Descer"
                                @click="moverOpcao(indiceOpcao, 1)"
                            >
                                <IconeAcao nome="descer" />
                                <span class="sr-only">Descer{{ ` opção ${indiceOpcao + 1}` }}</span>
                            </button>
                            <button
                                type="button"
                                class="acao-icone acao-icone-perigo"
                                data-dica="Remover"
                                @click="removerOpcao(indiceOpcao)"
                            >
                                <IconeAcao nome="remover" />
                                <span class="sr-only">Remover{{ ` opção ${indiceOpcao + 1}` }}</span>
                            </button>
                        </div>
                    </div>
                </li>
            </ol>

            <p v-if="erro('opcoes')" :id="idCampo('opcoes-erro')" role="alert" class="mt-2 text-[0.875rem] font-semibold text-invalido-texto">{{ erro('opcoes') }}</p>

            <button
                ref="botaoAdicionarOpcao"
                type="button"
                :disabled="pergunta.opcoes.length >= MAXIMO_OPCOES"
                :aria-invalid="erro('opcoes') && pergunta.opcoes.length === 0 ? true : undefined"
                :aria-describedby="erro('opcoes') ? idCampo('opcoes-erro') : undefined"
                class="pressionavel mt-3 inline-flex min-h-11 items-center gap-2 rounded-pill border border-acao bg-superficie px-5 text-[0.8125rem] font-bold text-acao hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
                @click="adicionarOpcao"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Adicionar opção
            </button>
            <p class="sr-only" aria-live="polite">{{ anuncioOpcoes }}</p>
        </fieldset>

        <VinculoDaPergunta
            :id="idCampo('vinculo')"
            :model-value="pergunta"
            :categorias="categorias"
            :erro-categorias="erroDaLista('categorias') ?? erro('todas_categorias')"
            @limpar="limparVinculo"
        />

        <div class="flex min-w-0 flex-col gap-3">
            <CampoCadastro
                :id="idCampo('condicao_pergunta')"
                rotulo="Mostrar só quando…"
                ajuda="Uma pergunta anterior, de escolha ou de sim/não, teve uma das respostas marcadas."
                :erro="erro('condicao_pergunta')"
                v-slot="{ descricao, invalido }"
            >
                <select
                    :id="idCampo('condicao_pergunta')"
                    :value="pergunta.condicao_pergunta ?? ''"
                    :class="classeCampo"
                    :aria-invalid="invalido || undefined"
                    :aria-describedby="descricao"
                    @change="escolherOrigem"
                >
                    <option value="">Sempre (sem condição)</option>
                    <option v-for="outra in origensPossiveis" :key="outra.chave" :value="outra.chave">{{ rotuloDaOrigem(outra) }}</option>
                </select>
            </CampoCadastro>

            <p v-if="origemDepois" role="alert" class="rounded-[12px] bg-aviso-fundo px-4 py-3 text-[0.9375rem] font-semibold text-aviso-texto">
                A pergunta de origem precisa vir antes desta.
            </p>

            <fieldset
                v-if="origem"
                class="min-w-0 border-l-2 border-hairline pl-4"
                :aria-describedby="erroDaLista('condicao_opcoes') ? idCampo('condicao_opcoes-erro') : undefined"
            >
                <legend class="text-[0.9375rem] font-semibold">
                    …a resposta for<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                </legend>
                <p v-if="respostasDaOrigem(origem).length === 0" :class="[classeDica, 'py-2']">Cadastre as opções da pergunta de origem.</p>
                <div class="grid gap-x-6 @lg:grid-cols-2" :class="{ 'campo-invalido mt-1 rounded-[12px] px-3 py-1': erroDaLista('condicao_opcoes') }">
                    <Interruptor
                        v-for="resposta in respostasDaOrigem(origem)"
                        :id="idCampo(`condicao-${resposta.chave}`)"
                        :key="resposta.chave"
                        :rotulo="resposta.rotulo"
                        variante="linha"
                        :invalido="Boolean(erroDaLista('condicao_opcoes'))"
                        :descricao="erroDaLista('condicao_opcoes') ? idCampo('condicao_opcoes-erro') : undefined"
                        :model-value="pergunta.condicao_opcoes.includes(resposta.chave)"
                        @update:model-value="marcarResposta(resposta.chave, $event)"
                    />
                </div>
                <p v-if="erroDaLista('condicao_opcoes')" :id="idCampo('condicao_opcoes-erro')" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">
                    {{ erroDaLista('condicao_opcoes') }}
                </p>
            </fieldset>
        </div>

        <div v-if="!desabilitado && outrasSecoes.length > 0" class="flex min-w-0 flex-col gap-1.5">
            <label :for="idCampo('mover')" class="text-[0.9375rem] font-semibold">Mover para a seção</label>
            <div class="flex flex-wrap items-center gap-3">
                <select :id="idCampo('mover')" v-model="destino" :class="[classeCampoBase, 'min-w-0 flex-1 basis-60']" :aria-describedby="idCampo('mover-ajuda')">
                    <option value="">Escolha a seção</option>
                    <option v-for="{ secao: outra, indice } in outrasSecoes" :key="outra.chave" :value="outra.chave" :disabled="secaoCheia(outra.chave)">
                        {{ rotuloDaSecao(outra, indice) }}
                    </option>
                </select>
                <button
                    type="button"
                    :disabled="destino === ''"
                    class="pressionavel inline-flex min-h-12 items-center justify-center rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
                    @click="mover"
                >
                    Mover<span class="sr-only"> a pergunta</span>
                </button>
            </div>
            <p :id="idCampo('mover-ajuda')" :class="classeDica">A pergunta vai para o fim da seção escolhida.</p>
        </div>
    </fieldset>
</template>
