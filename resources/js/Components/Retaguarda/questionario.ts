import type { PerguntaEditavel, SecaoQuestionarioEditavel, VinculoEditavel } from '../../types';

/**
 * Partes comuns da aba Questionário do formulário do edital: regras de cada tipo de pergunta (espelho de
 * App\Enums\TipoPergunta), chave das opções, ordem global das perguntas, vínculo e respostas da origem de uma condição.
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
