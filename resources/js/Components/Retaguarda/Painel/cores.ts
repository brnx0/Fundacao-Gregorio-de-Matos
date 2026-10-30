// Cores dos gráficos do Painel, pelos tokens de app.css (claro, noturno e alto contraste trocam sozinhos).
// As classes ficam escritas por inteiro para o Tailwind gerá-las.

export type CorDeSerie = { fundo: string; preenche: string; traco: string };

/**
 * Séries na ordem fixa: a cor segue a categoria (a posição dela no enum), nunca o ranking, e não se recicla.
 * Até 8 séries; o "não informado" usa NEUTRA.
 */
export const SERIES: CorDeSerie[] = [
    { fundo: 'bg-serie-1', preenche: 'fill-serie-1', traco: 'stroke-serie-1' },
    { fundo: 'bg-serie-2', preenche: 'fill-serie-2', traco: 'stroke-serie-2' },
    { fundo: 'bg-serie-3', preenche: 'fill-serie-3', traco: 'stroke-serie-3' },
    { fundo: 'bg-serie-4', preenche: 'fill-serie-4', traco: 'stroke-serie-4' },
    { fundo: 'bg-serie-5', preenche: 'fill-serie-5', traco: 'stroke-serie-5' },
    { fundo: 'bg-serie-6', preenche: 'fill-serie-6', traco: 'stroke-serie-6' },
    { fundo: 'bg-serie-7', preenche: 'fill-serie-7', traco: 'stroke-serie-7' },
    { fundo: 'bg-serie-8', preenche: 'fill-serie-8', traco: 'stroke-serie-8' },
];

export const NEUTRA: CorDeSerie = { fundo: 'bg-serie-neutra', preenche: 'fill-serie-neutra', traco: 'stroke-serie-neutra' };

/** Valores que são "não informado" em qualquer enum: ficam com a cor neutra, fora da ordem das séries. */
const SEM_RESPOSTA = ['nao_informada', 'nao_informado', 'sem_periodo', ''];

/** Cor da categoria na posição dela (ordem do enum); "não informado" é neutro. */
export const corDaSerie = (indice: number, valor = ''): CorDeSerie =>
    valor !== '' && SEM_RESPOSTA.includes(valor) ? NEUTRA : (SERIES[indice] ?? NEUTRA);

/**
 * Escala sequencial (mais = mais forte), com o texto que fica legível por cima de cada degrau. Degrau 0 é "nada".
 * Ordinal (etapas de funil, faixas em ordem) usa os degraus 3 a 6, que contrastam com o fundo.
 */
export const CALOR = [
    { fundo: 'bg-calor-0', texto: 'text-tinta-suave', traco: 'stroke-calor-0' },
    { fundo: 'bg-calor-1', texto: 'text-sobre-calor-baixo', traco: 'stroke-calor-1' },
    { fundo: 'bg-calor-2', texto: 'text-sobre-calor-baixo', traco: 'stroke-calor-2' },
    { fundo: 'bg-calor-3', texto: 'text-sobre-calor-baixo', traco: 'stroke-calor-3' },
    { fundo: 'bg-calor-4', texto: 'text-sobre-calor-alto', traco: 'stroke-calor-4' },
    { fundo: 'bg-calor-5', texto: 'text-sobre-calor-alto', traco: 'stroke-calor-5' },
    { fundo: 'bg-calor-6', texto: 'text-sobre-calor-alto', traco: 'stroke-calor-6' },
];

/** Degrau (1 a 6) de um valor sobre o máximo da escala; 0 quando não há nada. */
export const degrauDoCalor = (valor: number, maximo: number): number =>
    valor <= 0 || maximo <= 0 ? 0 : Math.min(6, Math.max(1, Math.ceil((valor / maximo) * 6)));
