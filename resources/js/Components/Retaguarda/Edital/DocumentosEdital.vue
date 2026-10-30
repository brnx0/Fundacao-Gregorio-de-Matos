<script setup lang="ts">
import { inject, reactive, ref } from 'vue';
import CampoArquivo from '../../CampoArquivo.vue';
import CampoCadastro from '../../CampoCadastro.vue';
import ListaEditavel from './ListaEditavel.vue';
import { SOMENTE_LEITURA, classeCaixa, classeCampo, classeDica, classeMarcacao, classeTexto, novaChave } from '../listaEditavel';
import type { CategoriaEditavel, DocumentoEditavel, OpcaoFormulario } from '../../../types';

const props = defineProps<{
    /** Erros do useForm; os dos documentos vêm como "documentos.N.campo". */
    erros: Partial<Record<string, string>>;
    naturezas: OpcaoFormulario[];
    etapas: OpcaoFormulario[];
    modalidadesCota: OpcaoFormulario[];
    checagensDocumento: OpcaoFormulario[];
    /** Categorias atuais da aba Categorias, na ordem em que estão. */
    categorias: CategoriaEditavel[];
    /** Dados do edital como estão no formulário (salvo ou não), para a IA gerar as instruções. */
    contextoEdital: { titulo: string; objeto: string; naturezas: string[] };
}>();

const emit = defineEmits<{
    limparErros: [campos: string[]];
    /** Erro do navegador num campo (arquivo grande demais). */
    definirErro: [campo: string, mensagem: string];
}>();

const documentos = defineModel<DocumentoEditavel[]>({ required: true });

/** Mesmo limite do SalvarEditalRequest (max:10240 KB): o arquivo grande nem sai do navegador. */
const LIMITE_MODELO = 10 * 1024 * 1024;
const ACEITOS_MODELO =
    '.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.oasis.opendocument.text,application/vnd.oasis.opendocument.spreadsheet';

/** Campos que aparecem no cartão: só os erros deles contam no selo (id e remover_modelo não mostram mensagem). */
const CAMPOS_VISIVEIS = /^(nome|orientacao|etapa|obrigatorio|naturezas|todas_categorias|categorias|modalidade_cota|checagem|instrucao_ia|modelo)(\.|$)/;

const rotulo = (opcoes: OpcaoFormulario[], valor: string): string | undefined => opcoes.find((opcao) => opcao.valor === valor)?.rotulo;

const rotuloDaCategoria = (categoria: CategoriaEditavel, posicao: number): string => categoria.nome.trim() || `Categoria ${posicao + 1}`;

const resumo = (documento: DocumentoEditavel): string =>
    [rotulo(props.etapas, documento.etapa) ?? 'Etapa a escolher', documento.obrigatorio ? 'Obrigatório' : 'Opcional', documento.modeloNome || documento.modelo ? 'Com arquivo-modelo' : null]
        .filter(Boolean)
        .join(' · ');

const escolherModelo = (indice: number, documento: DocumentoEditavel, arquivo: File | null): void => {
    emit('limparErros', [`documentos.${indice}.modelo`]);

    if (arquivo && arquivo.size > LIMITE_MODELO) {
        documento.modelo = null;
        emit('definirErro', `documentos.${indice}.modelo`, 'O arquivo-modelo deve ter no máximo 10 MB.');
        return;
    }
    documento.modelo = arquivo;
    if (arquivo) documento.remover_modelo = false;
};

const escolherTodas = (documento: DocumentoEditavel, todas: boolean, limparLista: (campo: string) => void): void => {
    documento.todas_categorias = todas;
    limparLista('categorias');
};

const novo = (): DocumentoEditavel => ({
    chave: novaChave(),
    id: null,
    nome: '',
    orientacao: '',
    etapa: props.etapas[0]?.valor ?? '',
    obrigatorio: true,
    naturezas: [],
    todas_categorias: true,
    categorias: [],
    modalidade_cota: '',
    checagem: 'aceita_sem_validar',
    instrucao_ia: '',
    modelo: null,
    remover_modelo: false,
    modeloUrl: null,
    modeloNome: null,
});

// Instruções geradas pela IA ("Gerar com IA" no cartão, "Gerar instruções de todos" no topo). Só preenchem o
// formulário: nada é gravado até a equipe salvar o edital.

// Caminho da rota retaguarda.editais.instrucoes-ia (o front não tem helper de rotas nomeadas).
const URL_INSTRUCOES = '/retaguarda/editais/instrucoes-ia';
/** Aviso e carregamento de "Gerar instruções de todos"; os dos cartões usam a chave do documento ("k1", "k2"…). */
const TODOS = 'todos';
const SESSAO_EXPIRADA = 'Sua sessão expirou. Recarregue a página e entre de novo.';

type RespostaInstrucoes = { ok: true; instrucoes: string[] } | { ok: false; mensagem: string };

/** Uma geração por vez: TODOS ou a chave do documento; null = nenhuma. */
const gerando = ref<string | null>(null);
const avisos = reactive<Record<string, { mensagem: string; erro: boolean }>>({});

const avisar = (chave: string, mensagem: string, erro: boolean): void => {
    avisos[chave] = { mensagem, erro };
};

const tokenXsrf = (): string => {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

const mensagemDeFalha = async (resposta: Response): Promise<string> => {
    if (resposta.status === 422) {
        // Falha da IA vem em "erro"; dado recusado na validação, em "errors".
        const corpo = (await resposta.json().catch(() => ({}))) as { erro?: string };
        return corpo.erro ?? 'Confira o nome e a etapa dos documentos e tente de novo.';
    }
    if (resposta.status === 429) return 'Muitas gerações seguidas. Aguarde um minuto e tente de novo.';
    if (resposta.status === 401 || resposta.status === 419) return SESSAO_EXPIRADA;
    return 'Não foi possível gerar as instruções agora. Tente de novo.';
};

/** Uma instrução por documento, na ordem da lista. Sempre resolve: qualquer falha vira { ok: false, mensagem }. */
const pedirInstrucoes = async (lista: DocumentoEditavel[]): Promise<RespostaInstrucoes> => {
    try {
        const resposta = await fetch(URL_INSTRUCOES, {
            method: 'POST',
            credentials: 'same-origin',
            // Quem deixou de ser gestor é mandado para o login: não segue o redirecionamento, trata como sessão expirada.
            redirect: 'manual',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': tokenXsrf(),
            },
            body: JSON.stringify({
                edital: {
                    ...props.contextoEdital,
                    categorias: props.categorias.map((categoria) => categoria.nome.trim()).filter((nome) => nome !== ''),
                },
                documentos: lista.map((documento) => ({
                    nome: documento.nome.trim(),
                    orientacao: documento.orientacao,
                    etapa: documento.etapa,
                })),
            }),
        });
        if (resposta.type === 'opaqueredirect') return { ok: false, mensagem: SESSAO_EXPIRADA };
        if (!resposta.ok) return { ok: false, mensagem: await mensagemDeFalha(resposta) };
        const { instrucoes } = (await resposta.json()) as { instrucoes: string[] };
        return { ok: true, instrucoes };
    } catch {
        return { ok: false, mensagem: 'Sem conexão com o servidor. Verifique a internet e tente de novo.' };
    }
};

/** Põe a instrução no documento e limpa o erro do campo; false se ele saiu da lista enquanto a IA respondia. */
const preencher = (documento: DocumentoEditavel, instrucao: string): boolean => {
    const indice = documentos.value.findIndex((item) => item.chave === documento.chave);
    const atual = documentos.value[indice];
    if (!atual) return false;

    atual.instrucao_ia = instrucao;
    emit('limparErros', [`documentos.${indice}.instrucao_ia`]);
    return true;
};

/** "Gerar com IA" do cartão: sobrescreve a instrução daquele documento. */
const gerarDoDocumento = async (documento: DocumentoEditavel): Promise<void> => {
    const chave = documento.chave;
    if (documento.nome.trim() === '') {
        avisar(chave, 'Preencha o nome do documento antes de gerar a instrução.', true);
        return;
    }

    gerando.value = chave;
    avisar(chave, '', false);
    const resposta = await pedirInstrucoes([documento]);
    gerando.value = null;

    if (!resposta.ok) {
        avisar(chave, resposta.mensagem, true);
    } else if (preencher(documento, resposta.instrucoes[0] ?? '')) {
        avisar(chave, 'Instrução gerada. Revise e salve o edital para gravá-la.', false);
    }
};

/** "Gerar instruções de todos": só os documentos com nome e sem instrução; o que a equipe escreveu fica. */
const gerarTodos = async (): Promise<void> => {
    const semInstrucao = documentos.value.filter((documento) => documento.instrucao_ia.trim() === '');
    const comNome = semInstrucao.filter((documento) => documento.nome.trim() !== '');
    if (comNome.length === 0) {
        if (semInstrucao.length === 0) avisar(TODOS, 'Todos os documentos já têm instrução.', false);
        else avisar(TODOS, 'Preencha o nome dos documentos antes de gerar as instruções.', true);
        return;
    }

    gerando.value = TODOS;
    avisar(TODOS, '', false);
    const resposta = await pedirInstrucoes(comNome);
    gerando.value = null;

    if (!resposta.ok) {
        avisar(TODOS, resposta.mensagem, true);
        return;
    }

    let geradas = 0;
    comNome.forEach((documento, posicao) => {
        // Escrita pela equipe enquanto a IA respondia: fica a da equipe.
        if (documento.instrucao_ia.trim() === '' && preencher(documento, resposta.instrucoes[posicao] ?? '')) geradas++;
    });
    const semNome = semInstrucao.length - comNome.length;
    avisar(
        TODOS,
        [
            `${geradas} ${geradas === 1 ? 'instrução gerada' : 'instruções geradas'}. Revise e salve o edital para gravá-las.`,
            semNome === 0 ? '' : semNome === 1 ? 'Um documento sem nome ficou de fora.' : `${semNome} documentos sem nome ficaram de fora.`,
        ]
            .filter(Boolean)
            .join(' '),
        false,
    );
};

// Só para ler (Visualizar): sem gerar com IA nem enviar ou remover o arquivo-modelo; o arquivo atual continua no link.
const somenteLeitura = inject(SOMENTE_LEITURA, false);

const classeBotaoIa =
    'pressionavel inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-wait disabled:opacity-70';
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-if="documentos.length > 0 && !somenteLeitura" class="flex flex-col gap-2">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">
                    A IA escreve a instrução de cada documento a partir do nome, da orientação, da etapa e dos dados do edital. Só os documentos sem instrução são preenchidos.
                </p>
                <button type="button" :disabled="gerando !== null" :class="classeBotaoIa" @click="gerarTodos">
                    <svg
                        v-if="gerando === TODOS"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        class="size-4 animate-spin motion-reduce:animate-none"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                    >
                        <path d="M12 3a9 9 0 1 0 9 9" />
                    </svg>
                    <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" />
                    </svg>
                    {{ gerando === TODOS ? 'Gerando…' : 'Gerar instruções de todos' }}
                </button>
            </div>
            <p aria-live="polite" class="text-[0.9375rem]" :class="avisos[TODOS]?.erro ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'">{{ avisos[TODOS]?.mensagem }}</p>
        </div>

        <ListaEditavel
            v-model="documentos"
            id="documento"
            prefixo="documentos"
            :erros="erros"
            singular="documento"
            plural="documentos"
            vazio="Nenhum documento exigido."
            :novo="novo"
            campo-inicial="nome"
            :nome="(documento) => documento.nome"
            :resumo="resumo"
            :conta-no-selo="(campo) => CAMPOS_VISIVEIS.test(campo)"
            @limpar-erros="emit('limparErros', $event)"
        >
            <template #default="{ item: documento, indice, idCampo, erro, erroDaLista, limpar, limparLista }">
                <CampoCadastro :id="idCampo('nome')" rotulo="Nome" obrigatorio :erro="erro('nome')" v-slot="{ descricao, invalido }">
                    <input
                        :id="idCampo('nome')"
                        v-model="documento.nome"
                        type="text"
                        maxlength="255"
                        required
                        placeholder="Ex.: Comprovante de residência"
                        :class="classeCampo"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="limpar('nome')"
                    />
                </CampoCadastro>

                <CampoCadastro :id="idCampo('orientacao')" rotulo="Orientação" ajuda="Texto curto mostrado ao proponente." :erro="erro('orientacao')" v-slot="{ descricao, invalido }">
                    <textarea
                        :id="idCampo('orientacao')"
                        v-model="documento.orientacao"
                        rows="2"
                        maxlength="1000"
                        :class="classeTexto"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @input="limpar('orientacao')"
                    />
                </CampoCadastro>

                <div class="grid gap-x-6 gap-y-5 @lg:grid-cols-2">
                    <CampoCadastro :id="idCampo('etapa')" rotulo="Etapa" obrigatorio :erro="erro('etapa')" v-slot="{ descricao, invalido }">
                        <select
                            :id="idCampo('etapa')"
                            v-model="documento.etapa"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="limpar('etapa')"
                        >
                            <option v-for="etapa in etapas" :key="etapa.valor" :value="etapa.valor">{{ etapa.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro
                        :id="idCampo('modalidade_cota')"
                        rotulo="Modalidade de cota"
                        ajuda="Para os documentos da validação de cotas."
                        :erro="erro('modalidade_cota')"
                        v-slot="{ descricao, invalido }"
                    >
                        <select
                            :id="idCampo('modalidade_cota')"
                            v-model="documento.modalidade_cota"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="limpar('modalidade_cota')"
                        >
                            <option value="">Nenhuma</option>
                            <option v-for="modalidade in modalidadesCota" :key="modalidade.valor" :value="modalidade.valor">{{ modalidade.rotulo }}</option>
                        </select>
                    </CampoCadastro>
                </div>

                <label :class="classeMarcacao">
                    <input v-model="documento.obrigatorio" type="checkbox" :class="classeCaixa" @change="limpar('obrigatorio')" />
                    Obrigatório
                </label>

                <fieldset class="min-w-0" :aria-describedby="[idCampo('naturezas-ajuda'), erroDaLista('naturezas') && idCampo('naturezas-erro')].filter(Boolean).join(' ')">
                    <legend class="text-[0.9375rem] font-semibold">Para quem</legend>
                    <p :id="idCampo('naturezas-ajuda')" :class="[classeDica, 'mt-1']">Nenhuma marcada = todas as naturezas.</p>
                    <div class="mt-1.5 grid gap-x-6 @lg:grid-cols-2">
                        <label v-for="natureza in naturezas" :key="natureza.valor" :class="classeMarcacao">
                            <input
                                v-model="documento.naturezas"
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

                <fieldset class="min-w-0" :aria-describedby="erroDaLista('categorias') ? idCampo('categorias-erro') : undefined">
                    <legend class="text-[0.9375rem] font-semibold">
                        Categorias<span v-if="!documento.todas_categorias" aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                    </legend>
                    <div class="mt-1.5 flex flex-wrap gap-x-6">
                        <label :class="classeMarcacao">
                            <input
                                type="radio"
                                :name="idCampo('todas')"
                                :checked="documento.todas_categorias"
                                :class="classeCaixa"
                                @change="escolherTodas(documento, true, limparLista)"
                            />
                            Todas as categorias
                        </label>
                        <label :class="classeMarcacao">
                            <input
                                type="radio"
                                :name="idCampo('todas')"
                                :checked="!documento.todas_categorias"
                                :class="classeCaixa"
                                @change="escolherTodas(documento, false, limparLista)"
                            />
                            Só algumas
                        </label>
                    </div>
                    <div v-if="!documento.todas_categorias" class="mt-1 grid gap-x-6 border-l-2 border-hairline pl-4 @lg:grid-cols-2">
                        <p v-if="categorias.length === 0" :class="[classeDica, 'py-2']">Cadastre as categorias na aba Categorias.</p>
                        <label v-for="(categoria, posicao) in categorias" :key="categoria.chave" :class="classeMarcacao">
                            <input
                                v-model="documento.categorias"
                                type="checkbox"
                                :value="categoria.chave"
                                :required="documento.categorias.length === 0"
                                :class="classeCaixa"
                                :aria-invalid="erroDaLista('categorias') ? true : undefined"
                                @change="limparLista('categorias')"
                            />
                            {{ rotuloDaCategoria(categoria, posicao) }}
                        </label>
                    </div>
                    <p v-if="erroDaLista('categorias')" :id="idCampo('categorias-erro')" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">{{ erroDaLista('categorias') }}</p>
                </fieldset>

                <CampoCadastro :id="idCampo('checagem')" rotulo="Checagem pela IA" obrigatorio :erro="erro('checagem')" v-slot="{ descricao, invalido }">
                    <select
                        :id="idCampo('checagem')"
                        v-model="documento.checagem"
                        required
                        :class="classeCampo"
                        :aria-invalid="invalido || undefined"
                        :aria-describedby="descricao"
                        @change="limpar('checagem')"
                    >
                        <option v-for="checagem in checagensDocumento" :key="checagem.valor" :value="checagem.valor">{{ checagem.rotulo }}</option>
                    </select>
                </CampoCadastro>

                <div class="flex min-w-0 flex-col gap-3">
                    <CampoCadastro
                        :id="idCampo('instrucao_ia')"
                        rotulo="Instrução para a IA"
                        ajuda="O que a IA deve conferir neste documento. Não aparece para o proponente."
                        :erro="erro('instrucao_ia')"
                        v-slot="{ descricao, invalido }"
                    >
                        <textarea
                            :id="idCampo('instrucao_ia')"
                            v-model="documento.instrucao_ia"
                            rows="3"
                            maxlength="2000"
                            :class="classeTexto"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="limpar('instrucao_ia')"
                        />
                    </CampoCadastro>
                    <div v-if="!somenteLeitura" class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <button type="button" :disabled="gerando !== null" :class="classeBotaoIa" @click="gerarDoDocumento(documento)">
                            <svg
                                v-if="gerando === documento.chave"
                                aria-hidden="true"
                                viewBox="0 0 24 24"
                                class="size-4 animate-spin motion-reduce:animate-none"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                            >
                                <path d="M12 3a9 9 0 1 0 9 9" />
                            </svg>
                            <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" />
                            </svg>
                            {{ gerando === documento.chave ? 'Gerando…' : 'Gerar com IA' }}<span class="sr-only">{{ ` a instrução do documento ${indice + 1}` }}</span>
                        </button>
                        <p
                            aria-live="polite"
                            class="min-w-0 flex-1 basis-60 text-[0.9375rem]"
                            :class="avisos[documento.chave]?.erro ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'"
                        >
                            {{ avisos[documento.chave]?.mensagem }}
                        </p>
                    </div>
                </div>

                <div class="flex min-w-0 flex-col gap-3">
                    <CampoCadastro v-if="!somenteLeitura" :id="idCampo('modelo')" rotulo="Arquivo-modelo" :erro="erro('modelo')" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            :id="idCampo('modelo')"
                            :accept="ACEITOS_MODELO"
                            dica="PDF, DOC, DOCX, XLS, XLSX, ODT ou ODS, até 10 MB. O proponente baixa, preenche e envia"
                            :arquivo="documento.modelo"
                            :enviado="Boolean(documento.modeloUrl) && !documento.remover_modelo"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherModelo(indice, documento, $event)"
                        />
                    </CampoCadastro>
                    <div v-if="documento.modeloUrl && !documento.modelo" class="flex flex-wrap items-center gap-x-6 gap-y-1">
                        <p class="min-w-0 text-[0.9375rem]" :class="{ 'line-through opacity-60': documento.remover_modelo }">
                            {{ somenteLeitura ? 'Arquivo-modelo:' : 'Arquivo atual:' }}
                            <a :href="documento.modeloUrl" class="font-semibold break-all text-acao underline">{{ documento.modeloNome ?? 'baixar' }}</a>
                        </p>
                        <label v-if="!somenteLeitura" :class="classeMarcacao">
                            <input v-model="documento.remover_modelo" type="checkbox" :class="classeCaixa" />
                            Remover arquivo
                        </label>
                    </div>
                    <p v-else-if="somenteLeitura" :class="classeDica">Sem arquivo-modelo.</p>
                </div>
            </template>
        </ListaEditavel>
    </div>
</template>
