import { nextTick, reactive, ref, watch, type InjectionKey, type Ref } from 'vue';
import type { ItemEditavel } from '../../types';

/**
 * Partes comuns das listas editáveis do formulário do edital: chave estável dos cartões, filtros de números
 * sem sinal, classes dos campos e a sanfona com subir/descer/remover (useListaEditavel, usada por ListaEditavel.vue).
 */

/** Mesmo limite do SalvarEditalRequest (MAXIMO_ITENS). */
export const MAXIMO_ITENS = 50;

/**
 * Formulário do edital aberto só para ler (ícone Visualizar da lista): os campos ficam bloqueados e os componentes de
 * dentro escondem o que é de editar (adicionar, mover, remover, gerar com IA, enviar arquivo).
 */
export const SOMENTE_LEITURA: InjectionKey<boolean> = Symbol('somenteLeitura');

// Chave do cartão: não muda ao reordenar e é única na página (os ids dos campos são montados com ela).
let proximaChave = 0;
export const novaChave = (): string => `k${++proximaChave}`;

// Listas salvas viram cartões com chave própria; as referências por posição do servidor viram chaves dos cartões.
export const chaveNaPosicao = (lista: ItemEditavel[], posicao: number | null): string | null => (posicao === null ? null : (lista[posicao]?.chave ?? null));
export const chavesNasPosicoes = (lista: ItemEditavel[], posicoes: number[]): string[] => posicoes.flatMap((posicao) => lista[posicao]?.chave ?? []);

// Na volta, as chaves viram posições na lista enviada.
export const posicoes = (lista: ItemEditavel[]): Map<string, number> => new Map(lista.map((item, posicao) => [item.chave, posicao]));
export const emPosicoes = (chaves: string[], mapa: Map<string, number>): number[] =>
    chaves
        .map((chave) => mapa.get(chave))
        .filter((posicao): posicao is number => posicao !== undefined)
        .sort((a, b) => a - b);

/**
 * Só dígitos (sem sinal, ponto, vírgula ou "e"), limitado a `maximo`; devolve o número e reescreve o campo.
 * Vazio vira null; zero também, a menos que `aceitaZero`.
 */
export const inteiro = (evento: Event, maximo: number, aceitaZero = false): number | null => {
    const campo = evento.target as HTMLInputElement;
    const digitos = campo.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, String(maximo).length);
    let numero = digitos === '' ? null : Math.min(Number(digitos), maximo);
    if (numero === 0 && !aceitaZero) numero = null;

    campo.value = numero === null ? '' : String(numero);

    return numero;
};

/**
 * Número decimal sem sinal como o usuário digita ("12,5"): só dígitos e uma vírgula (ponto vira vírgula), com até
 * `casas` decimais e `digitosInteiros` antes da vírgula. Reescreve o campo e devolve o texto, que fica no formulário
 * como texto para não perder a vírgula enquanto se digita; numeroDecimal() converte no envio.
 */
export const decimal = (evento: Event, digitosInteiros = 4, casas = 2): string => {
    const campo = evento.target as HTMLInputElement;
    const [inteiros = '', ...resto] = campo.value.replace(/\./g, ',').replace(/[^\d,]/g, '').split(',');
    const parteInteira = inteiros.replace(/^0+(?=\d)/, '').slice(0, digitosInteiros);
    const texto = resto.length > 0 ? `${parteInteira || '0'},${resto.join('').slice(0, casas)}` : parteInteira;

    campo.value = texto;

    return texto;
};

/** Texto de decimal() para número (vazio = null). */
export const numeroDecimal = (texto: string): number | null => {
    const limpo = texto.replace(',', '.').replace(/\.$/, '');

    return limpo === '' ? null : Number(limpo);
};

/** Número salvo para o texto do campo decimal ("12,5"). */
export const textoDecimal = (numero: number | null | undefined): string => (numero === null || numero === undefined ? '' : String(numero).replace('.', ','));

// Valor em reais com máscara: os dígitos digitados são centavos (1500000 → R$ 15.000,00).
const formatoReais = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
export const textoReais = (valor: number | null): string => (valor === null ? '' : formatoReais.format(valor));

/** Mesmo limite de valor do SalvarEditalRequest (10 bilhões), em dígitos de centavos. */
const DIGITOS_REAIS = 12;

/** Lê o campo de reais (dígitos = centavos), reescreve com a máscara e devolve o valor; vazio ou zero vira null. */
export const reais = (evento: Event): number | null => {
    const campo = evento.target as HTMLInputElement;
    const digitos = campo.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, DIGITOS_REAIS);
    const valor = digitos === '' ? null : Number(digitos) / 100;

    campo.value = textoReais(valor);

    return valor;
};

/** Opções de um sim/não que pode ficar sem resposta (o edital não informa). */
export const OPCOES_SIM_NAO: { valor: boolean | null; rotulo: string }[] = [
    { valor: null, rotulo: 'Não informado' },
    { valor: true, rotulo: 'Sim' },
    { valor: false, rotulo: 'Não' },
];

// Sem largura: campos de largura fixa a acrescentam; os demais ocupam a linha (classeCampo).
export const classeCampoBase =
    'h-12 rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 text-base text-home-tinta placeholder:text-tinta-suave';
export const classeCampo = `${classeCampoBase} w-full`;
export const classeTexto = `${classeCampo} h-auto py-3 leading-[1.6]`;
export const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
export const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
export const classeDica = 'text-[0.8125rem] leading-[1.5] text-tinta-suave';

export interface OpcoesListaEditavel<T> {
    /** A lista (defineModel) do componente. */
    lista: Ref<T[]>;
    /** Prefixo das chaves de erro: "categorias" para "categorias.N.campo". */
    prefixo: () => string;
    erros: () => Partial<Record<string, string>>;
    limparErros: (campos: string[]) => void;
    idCampo: (item: T, campo: string) => string;
    textoMovido: (posicao: number, total: number) => string;
    textoRemovido: () => string;
    /** Quais campos com erro contam no selo do cartão (o resto de "prefixo.N."); sem isso, todos. */
    contaNoSelo?: (campo: string) => boolean;
}

/**
 * Sanfona de cartões com chave estável, erros por índice, subir/descer e remoção confirmada num <dialog>.
 * O componente liga `dialogo` e `botaoAdicionar` pelo atributo ref do template.
 */
export function useListaEditavel<T extends ItemEditavel>(opcoes: OpcoesListaEditavel<T>) {
    const { lista, prefixo, erros, limparErros, idCampo } = opcoes;

    // Cartões abertos, pela chave. Recolher só esconde (v-show): nada se perde.
    const abertos = reactive(new Set<string>());
    const estaAberto = (item: T): boolean => abertos.has(item.chave);
    const alternar = (item: T): void => {
        if (!abertos.delete(item.chave)) abertos.add(item.chave);
    };
    const todosAbertos = (): boolean => lista.value.length > 0 && lista.value.every((item) => abertos.has(item.chave));
    const alternarTodos = (): void => {
        const abrirTodos = !todosAbertos();
        for (const item of lista.value) {
            if (abrirTodos) abertos.add(item.chave);
            else abertos.delete(item.chave);
        }
    };

    const anuncio = ref<string>('');
    const botaoAdicionar = ref<HTMLButtonElement | null>(null);

    const erro = (indice: number, campo: string): string | undefined => erros()[`${prefixo()}.${indice}.${campo}`];

    /** Quantos campos do cartão têm erro (para o selo do cabeçalho). */
    const quantidadeDeErros = (indice: number): number => {
        const inicio = `${prefixo()}.${indice}.`;
        const todos = erros();

        return Object.keys(todos).filter((chave) => chave.startsWith(inicio) && todos[chave] && (opcoes.contaNoSelo?.(chave.slice(inicio.length)) ?? true)).length;
    };

    // Depois de um envio recusado, abre todo cartão com erro (antes do foco no primeiro campo inválido, que o formulário dá no próximo tick).
    watch(
        () => {
            const todos = erros();

            return Object.keys(todos)
                .filter((chave) => chave.startsWith(`${prefixo()}.`) && todos[chave])
                .join('|');
        },
        () => {
            lista.value.forEach((item, indice) => {
                if (quantidadeDeErros(indice) > 0) abertos.add(item.chave);
            });
        },
        { immediate: true },
    );

    /** Erro de uma lista de caixas: o do campo ou o do primeiro item ("campo.0"). */
    const erroDaLista = (indice: number, campo: string): string | undefined => {
        const todos = erros();
        const doItem = Object.keys(todos).find((chave) => chave.startsWith(`${prefixo()}.${indice}.${campo}.`) && todos[chave]);

        return erro(indice, campo) ?? (doItem ? todos[doItem] : undefined);
    };

    const limpar = (indice: number, ...campos: string[]): void => {
        limparErros(campos.map((campo) => `${prefixo()}.${indice}.${campo}`));
    };

    const limparLista = (indice: number, campo: string): void => {
        const chave = `${prefixo()}.${indice}.${campo}`;
        limparErros([chave, ...Object.keys(erros()).filter((outra) => outra.startsWith(`${chave}.`))]);
    };

    // Remover ou reordenar muda os índices: os erros do servidor deixam de apontar para o cartão certo.
    const limparTodosOsErros = (): void => {
        const chaves = Object.keys(erros()).filter((chave) => chave === prefixo() || chave.startsWith(`${prefixo()}.`));
        if (chaves.length > 0) limparErros(chaves);
    };

    const focar = async (id: string): Promise<void> => {
        await nextTick();
        // Depois do fechamento do <dialog>, que devolve o foco ao botão que o abriu (já removido).
        await new Promise((resolver) => setTimeout(resolver, 0));
        document.getElementById(id)?.focus();
    };

    /**
     * Novo item entra no fim (os índices dos outros não mudam, então os erros deles continuam valendo), aberto e com foco
     * em `campo`. O erro da lista inteira ("Cadastre ao menos uma…", para publicar) deixa de valer e sai da aba.
     */
    const acrescentar = (item: T, campo: string): void => {
        lista.value = [...lista.value, item];
        if (erros()[prefixo()]) limparErros([prefixo()]);
        abertos.add(item.chave);
        void focar(idCampo(item, campo));
    };

    // Subir/Descer: o foco fica no mesmo botão do cartão movido (ou no cartão, se o botão ficou desabilitado).
    const mover = (indice: number, direcao: -1 | 1, botao: 'subir' | 'descer'): void => {
        const destino = indice + direcao;
        const nova = [...lista.value];
        const movido = nova[indice];
        if (!movido || destino < 0 || destino >= nova.length) return;

        nova.splice(indice, 1);
        nova.splice(destino, 0, movido);
        lista.value = nova;
        limparTodosOsErros();
        anuncio.value = opcoes.textoMovido(destino + 1, nova.length);

        const nasPontas = (botao === 'subir' && destino === 0) || (botao === 'descer' && destino === nova.length - 1);
        void focar(nasPontas ? idCampo(movido, 'cartao') : idCampo(movido, botao));
    };

    // Remoção pede confirmação num <dialog> nativo (como ConfirmarExclusao), mas só tira da lista: vale ao salvar.
    const dialogo = ref<HTMLDialogElement | null>(null);
    const aRemover = ref<number | null>(null);

    const pedirRemocao = (indice: number): void => {
        aRemover.value = indice;
        dialogo.value?.showModal();
    };

    const cancelarRemocao = (): void => {
        dialogo.value?.close();
        aRemover.value = null;
    };

    const remover = (): void => {
        const indice = aRemover.value;
        dialogo.value?.close();
        aRemover.value = null;
        if (indice === null) return;

        lista.value = lista.value.filter((_, posicao) => posicao !== indice);
        limparTodosOsErros();
        anuncio.value = opcoes.textoRemovido();

        // Foca o anterior; se removeu o primeiro, o que ocupa o lugar dele; sem nenhum, o botão de adicionar.
        const anterior = lista.value[indice - 1] ?? lista.value[indice];
        if (anterior) {
            void focar(idCampo(anterior, 'cartao'));
        } else {
            void nextTick(() => setTimeout(() => botaoAdicionar.value?.focus(), 0));
        }
    };

    return {
        estaAberto,
        alternar,
        todosAbertos,
        alternarTodos,
        anuncio,
        botaoAdicionar,
        erro,
        quantidadeDeErros,
        erroDaLista,
        limpar,
        limparLista,
        acrescentar,
        mover,
        dialogo,
        aRemover,
        pedirRemocao,
        cancelarRemocao,
        remover,
    };
}
