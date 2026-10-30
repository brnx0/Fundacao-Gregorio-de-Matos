// Números do Painel em português do Brasil. O servidor manda os valores crus (contagens, percentuais com uma casa e
// dinheiro em centavos) porque os gráficos precisam deles para a escala; o texto sai daqui.

const inteiro = new Intl.NumberFormat('pt-BR');
const umaCasa = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
const reaisInteiros = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
const reaisCompactos = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 });
const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** 106346 → "106.346". */
export const numero = (valor: number): string => inteiro.format(valor);

/** 87.4 → "87,4%". */
export const percentual = (valor: number): string => `${umaCasa.format(valor)}%`;

/** Centavos → "R$ 1.250.000". */
export const reais = (centavos: number): string => reaisInteiros.format(centavos / 100);

/** Centavos → "R$ 1,3 mi", para caber em cartão e rótulo. */
export const reaisCurto = (centavos: number): string => reaisCompactos.format(centavos / 100);

/** "2026-05" → "mai/26". */
export const mesCurto = (mes: string): string => {
    const [ano, numeroDoMes] = mes.split('-');
    return `${MESES[Number(numeroDoMes) - 1]}/${ano.slice(2)}`;
};

/** "2026-05-09" → "09/05/2026". */
export const dataCurta = (dia: string): string => dia.split('-').reverse().join('/');

/** Singular ou plural pela quantidade: 1 inscrição, 2 inscrições. */
export const quantos = (valor: number, singular: string, plural: string): string => `${numero(valor)} ${valor === 1 ? singular : plural}`;
