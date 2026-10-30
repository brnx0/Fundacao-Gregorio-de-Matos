import { ref, watch, type Ref } from 'vue';
import type { ItemEditavel, PerguntaEditavel, QuestionarioFormulario, SecaoQuestionarioEditavel, VinculoEditavel } from '../../types';
import { chaveNaPosicao, chavesNasPosicoes, emPosicoes, novaChave, posicoes } from './listaEditavel';

/**
 * Partes comuns da tela do questionário do edital: regras de cada tipo de pergunta (espelho de
 * App\Enums\TipoPergunta), chave das opções, ordem global das perguntas, vínculo e respostas da origem de uma
 * condição, a ida e a volta do servidor e a limpeza das condições que deixam de valer.
 */

interface RegraDoTipo {
    /** Tem lista de opções cadastrada pelo gestor. */
    temOpcoes: boolean;
    /** Outra pergunta pode depender da resposta a esta. */
    podeSerOrigem: boolean;
    temLimite: boolean;
    limitePadrao: number | null;
}

const SEM_REGRAS: RegraDoTipo = { temOpcoes: false, podeSerOrigem: false, temLimite: false, limitePadrao: null };

const REGRAS: Record<string, RegraDoTipo> = {
    texto_curto: { ...SEM_REGRAS, temLimite: true, limitePadrao: 255 },
    texto_longo: { ...SEM_REGRAS, temLimite: true, limitePadrao: 20000 },
    escolha_unica: { ...SEM_REGRAS, temOpcoes: true, podeSerOrigem: true },
    multipla_escolha: { ...SEM_REGRAS, temOpcoes: true, podeSerOrigem: true },
    sim_nao: { ...SEM_REGRAS, podeSerOrigem: true },
};

export const TIPO_PADRAO = 'texto_curto';
/** Declaração: o proponente marca que concorda; é sempre obrigatória. */
export const TIPO_DECLARACAO = 'declaracao';
const TIPO_SIM_NAO = 'sim_nao';

/** Mesmo limite de RegrasDoQuestionario (limite_caracteres). */
export const LIMITE_MAXIMO_CARACTERES = 20000;

export const regraDoTipo = (tipo: string): RegraDoTipo => REGRAS[tipo] ?? SEM_REGRAS;

const ALFABETO = 'abcdefghijklmnopqrstuvwxyz0123456789';

/** Chave nova de opção: 12 caracteres [a-z0-9], sorteados sem viés (o servidor aceita até 20). */
export const novaChaveDeOpcao = (): string => {
    let chave = '';
    while (chave.length < 12) {
        for (const byte of crypto.getRandomValues(new Uint8Array(16))) {
            // 252 = 7 × 36: descarta o resto para cada caractere ter a mesma chance.
            if (byte < 252 && chave.length < 12) chave += ALFABETO.charAt(byte % ALFABETO.length);
        }
    }

    return chave;
};

/** Perguntas na ordem do questionário (a posição global que o servidor usa nas condições). */
export const perguntasEmOrdem = (secoes: SecaoQuestionarioEditavel[]): PerguntaEditavel[] => secoes.flatMap((secao) => secao.perguntas);

/** Nem "todas as categorias" nem alguma categoria: impede a publicação. */
export const semVinculo = (pergunta: VinculoEditavel): boolean => !pergunta.todas_categorias && pergunta.categorias.length === 0;

/** Respostas que uma condição pode escolher na pergunta de origem (sim/não ou as opções dela). */
export const respostasDaOrigem = (origem: PerguntaEditavel): { chave: string; rotulo: string }[] => {
    if (origem.tipo === TIPO_SIM_NAO) {
        return [
            { chave: 'sim', rotulo: 'Sim' },
            { chave: 'nao', rotulo: 'Não' },
        ];
    }
    if (!regraDoTipo(origem.tipo).temOpcoes) return [];

    return origem.opcoes.map((opcao, posicao) => ({ chave: opcao.chave, rotulo: opcao.texto.trim() || `Opção ${posicao + 1}` }));
};

/** Nome da pergunta nos avisos e nas listas. */
export const nomeDaPergunta = (pergunta: PerguntaEditavel): string => pergunta.enunciado.trim() || 'Pergunta sem enunciado';

/** Questionário do servidor em cartões: categorias por posição viram chaves; a origem da condição vem pela posição global. */
export const secoesEditaveis = (questionario: QuestionarioFormulario, categorias: ItemEditavel[]): SecaoQuestionarioEditavel[] => {
    const secoes: SecaoQuestionarioEditavel[] = questionario.secoes.map((secao) => ({
        chave: novaChave(),
        id: secao.id,
        titulo: secao.titulo,
        descricao: secao.descricao ?? '',
        perguntas: secao.perguntas.map((pergunta) => ({
            chave: novaChave(),
            id: pergunta.id,
            enunciado: pergunta.enunciado,
            ajuda: pergunta.ajuda ?? '',
            tipo: pergunta.tipo,
            obrigatoria: pergunta.obrigatoria,
            limite_caracteres: pergunta.limite_caracteres,
            opcoes: pergunta.opcoes.map((opcao) => ({ chave: opcao.chave, texto: opcao.texto, outro: Boolean(opcao.outro) })),
            todas_categorias: pergunta.todas_categorias,
            categorias: chavesNasPosicoes(categorias, pergunta.categorias),
            condicao_pergunta: null,
            condicao_opcoes: [...pergunta.condicao_opcoes],
        })),
    }));

    const perguntas = perguntasEmOrdem(secoes);
    questionario.secoes
        .flatMap((secao) => secao.perguntas)
        .forEach((pergunta, posicao) => {
            const editavel = perguntas[posicao];
            if (editavel) editavel.condicao_pergunta = chaveNaPosicao(perguntas, pergunta.condicao_pergunta);
        });

    return secoes;
};

/**
 * Questionário no contrato de RegrasDoQuestionario: categorias por posição, origem da condição por posição global;
 * opções só nos tipos de escolha e limite só nos textos (o servidor confere as opções de todo tipo).
 */
export const questionarioParaEnvio = (secoes: SecaoQuestionarioEditavel[], categorias: ItemEditavel[]): { secoes: Record<string, unknown>[] } => {
    const posicaoDaCategoria = posicoes(categorias);
    const posicaoGlobal = posicoes(perguntasEmOrdem(secoes));

    return {
        secoes: secoes.map((secao) => ({
            id: secao.id,
            titulo: secao.titulo,
            descricao: secao.descricao || null,
            perguntas: secao.perguntas.map((pergunta) => {
                const regra = regraDoTipo(pergunta.tipo);
                const condicao = pergunta.condicao_pergunta === null ? null : (posicaoGlobal.get(pergunta.condicao_pergunta) ?? null);

                return {
                    id: pergunta.id,
                    enunciado: pergunta.enunciado,
                    ajuda: pergunta.ajuda || null,
                    tipo: pergunta.tipo,
                    obrigatoria: pergunta.obrigatoria,
                    limite_caracteres: regra.temLimite ? pergunta.limite_caracteres : null,
                    opcoes: regra.temOpcoes ? pergunta.opcoes.map(({ chave, texto, outro }) => ({ chave, texto, outro })) : [],
                    todas_categorias: pergunta.todas_categorias,
                    categorias: pergunta.todas_categorias ? [] : emPosicoes(pergunta.categorias, posicaoDaCategoria),
                    condicao_pergunta: condicao,
                    condicao_opcoes: condicao === null ? [] : pergunta.condicao_opcoes,
                };
            }),
        })),
    };
};

export interface AvisoDoQuestionario {
    id: number;
    texto: string;
}

/**
 * Condição cuja origem sumiu (apagada, mudou de tipo) ou perdeu todas as respostas escolhidas sai, com aviso na tela.
 * Resposta tirada da origem (opção removida) sai da condição. Devolve os avisos da última mudança.
 */
export const vigiarCondicoes = (secoes: () => SecaoQuestionarioEditavel[]): Ref<AvisoDoQuestionario[]> => {
    const avisos = ref<AvisoDoQuestionario[]>([]);
    let proximoAviso = 0;

    const removerCondicao = (pergunta: PerguntaEditavel): void => {
        pergunta.condicao_pergunta = null;
        pergunta.condicao_opcoes = [];
    };

    watch(
        () =>
            perguntasEmOrdem(secoes())
                .map((pergunta) => `${pergunta.chave}:${pergunta.tipo}:${pergunta.opcoes.map((opcao) => opcao.chave).join(',')}`)
                .join('|'),
        () => {
            const perguntas = perguntasEmOrdem(secoes());
            const porChave = new Map(perguntas.map((pergunta) => [pergunta.chave, pergunta]));
            const novos: AvisoDoQuestionario[] = [];
            const avisar = (texto: string): void => {
                novos.push({ id: ++proximoAviso, texto });
            };

            for (const pergunta of perguntas) {
                if (pergunta.condicao_pergunta === null) continue;
                const origem = porChave.get(pergunta.condicao_pergunta);
                const nome = nomeDaPergunta(pergunta);

                if (!origem) {
                    removerCondicao(pergunta);
                    avisar(`A condição de "${nome}" foi removida porque a pergunta de origem foi apagada.`);
                    continue;
                }
                if (!regraDoTipo(origem.tipo).podeSerOrigem) {
                    removerCondicao(pergunta);
                    avisar(`A condição de "${nome}" foi removida porque a pergunta de origem mudou de tipo.`);
                    continue;
                }

                const validas = new Set(respostasDaOrigem(origem).map((resposta) => resposta.chave));
                const restantes = pergunta.condicao_opcoes.filter((chave) => validas.has(chave));
                if (restantes.length === pergunta.condicao_opcoes.length) continue;

                if (restantes.length === 0) {
                    removerCondicao(pergunta);
                    avisar(`A condição de "${nome}" foi removida porque as respostas escolhidas saíram da pergunta de origem.`);
                } else {
                    pergunta.condicao_opcoes = restantes;
                }
            }

            if (novos.length > 0) avisos.value = novos;
        },
    );

    return avisos;
};
