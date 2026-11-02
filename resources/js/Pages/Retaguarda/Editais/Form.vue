<script setup lang="ts">
import { computed, nextTick, provide, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../../Components/AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../../Components/CampoArquivo.vue';
import CampoCadastro from '../../../Components/CampoCadastro.vue';
import AbasFormulario from '../../../Components/Retaguarda/AbasFormulario.vue';
import CampoDescricaoDoCard from '../../../Components/Retaguarda/Edital/CampoDescricaoDoCard.vue';
import CategoriasEdital from '../../../Components/Retaguarda/Edital/CategoriasEdital.vue';
import ChamadasEdital from '../../../Components/Retaguarda/Edital/ChamadasEdital.vue';
import CotasEdital from '../../../Components/Retaguarda/Edital/CotasEdital.vue';
import CriteriosEdital from '../../../Components/Retaguarda/Edital/CriteriosEdital.vue';
import DocumentosEdital from '../../../Components/Retaguarda/Edital/DocumentosEdital.vue';
import InformacoesEdital from '../../../Components/Retaguarda/Edital/InformacoesEdital.vue';
import PreviaCardEdital from '../../../Components/Retaguarda/Edital/PreviaCardEdital.vue';
import QuadroImportacao from '../../../Components/Retaguarda/Edital/QuadroImportacao.vue';
import {
    OPCOES_SIM_NAO,
    SOMENTE_LEITURA,
    chaveNaPosicao,
    chavesNasPosicoes,
    classeCaixa,
    classeCampo,
    classeDica,
    classeMarcacao,
    classeTexto,
    decimal,
    emPosicoes,
    inteiro,
    novaChave,
    numeroDecimal,
    posicoes,
    textoDecimal,
} from '../../../Components/Retaguarda/listaEditavel';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type {
    AbaFormulario,
    CategoriaEditavel,
    ChamadaEditavel,
    CotaModalidadeFormulario,
    CotasEditavel,
    CriterioEditavel,
    DocumentoEditavel,
    RetaguardaEditaisFormProps,
} from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaEditaisFormProps>();

// Caminhos das rotas retaguarda.editais.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/editais';

const salvo = props.edital;
const editando = salvo !== null;
// "Voltar para a lista" e "Cancelar" voltam para a aba do edital salvo na lista.
const LISTA = salvo?.publicado ? `${BASE}?publicado=sim` : BASE;

// Visualizar (show) usa este mesmo formulário só para ler: as abas trocam, os campos ficam bloqueados
// (AbasFormulario) e os componentes de dentro escondem o que é de editar.
provide(SOMENTE_LEITURA, props.somenteLeitura);

const chamadasSalvas: ChamadaEditavel[] = (salvo?.chamadas ?? []).map((chamada) => ({
    chave: novaChave(),
    id: chamada.id,
    nome: chamada.nome,
    inicio: chamada.inicio ?? '',
    fim: chamada.fim ?? '',
    validacao_inicio: chamada.validacao_inicio ?? '',
    validacao_fim: chamada.validacao_fim ?? '',
    homologacao_inicio: chamada.homologacao_inicio ?? '',
    homologacao_fim: chamada.homologacao_fim ?? '',
}));

const categoriasSalvas: CategoriaEditavel[] = (salvo?.categorias ?? []).map((categoria) => ({
    chave: novaChave(),
    id: categoria.id,
    nome: categoria.nome,
    descricao: categoria.descricao ?? '',
    valor: categoria.valor,
    vagas: categoria.vagas,
    naturezas: [...categoria.naturezas],
    chamada: chaveNaPosicao(chamadasSalvas, categoria.chamada),
    itens: categoria.itens.map((item) => ({ chave: novaChave(), ...item })),
}));

const documentosSalvos: DocumentoEditavel[] = (salvo?.documentos ?? []).map((documento) => ({
    chave: novaChave(),
    id: documento.id,
    nome: documento.nome,
    orientacao: documento.orientacao ?? '',
    etapa: documento.etapa,
    obrigatorio: documento.obrigatorio,
    naturezas: [...documento.naturezas],
    todas_categorias: documento.todas_categorias,
    categorias: chavesNasPosicoes(categoriasSalvas, documento.categorias),
    modalidade_cota: documento.modalidade_cota ?? '',
    checagem: documento.checagem,
    instrucao_ia: documento.instrucao_ia ?? '',
    modelo: null,
    remover_modelo: false,
    modeloUrl: documento.modeloUrl,
    modeloNome: documento.modeloNome,
}));

const criteriosSalvos: CriterioEditavel[] = (salvo?.criterios ?? []).map((criterio) => ({
    chave: novaChave(),
    id: criterio.id,
    nome: criterio.nome,
    descricao: criterio.descricao ?? '',
    peso: textoDecimal(criterio.peso),
    pontos_maximos: textoDecimal(criterio.pontos_maximos),
    todas_categorias: criterio.todas_categorias,
    categorias: chavesNasPosicoes(categoriasSalvas, criterio.categorias),
}));

const cotasSalvas = salvo?.cotas ?? null;
const cotaSalva = (modalidade: string): CotaModalidadeFormulario | undefined =>
    (cotasSalvas as unknown as Record<string, CotaModalidadeFormulario | undefined> | null)?.[modalidade];

const cotas: CotasEditavel = {
    modalidades: Object.fromEntries(
        props.modalidadesCota.map((modalidade) => {
            const cota = cotaSalva(modalidade.valor);

            return [modalidade.valor, { ativa: cota?.ativa ?? false, percentual: textoDecimal(cota?.percentual), quantidade: textoDecimal(cota?.quantidade) }];
        }),
    ),
    base: cotasSalvas?.base ?? '',
    alcance: cotasSalvas?.alcance ?? '',
    veda_negro_indigena: cotasSalvas?.veda_negro_indigena ?? null,
    heteroidentificacao: cotasSalvas?.heteroidentificacao ?? null,
};

// Arquivos só vão quando escolhidos; sem novo upload o servidor mantém os atuais.
const form = useForm({
    numero: salvo?.numero ?? '',
    tipo: salvo?.tipo ?? '',
    titulo: salvo?.titulo ?? '',
    objeto: salvo?.objeto ?? '',
    dom_data: salvo?.dom_data ?? '',
    dom_numero: salvo?.dom_numero ?? '',
    categoria: salvo?.categoria ?? '',
    resumo: salvo?.resumo ?? '',
    chamadas: chamadasSalvas,
    forma_inscricao_original: salvo?.forma_inscricao_original ?? '',
    naturezas: [...(salvo?.naturezas ?? [])],
    aceita_coletivos: salvo?.aceita_coletivos ?? null,
    // Padrão do edital comum: uma inscrição por proponente.
    inscricoes_por_proponente: editando ? salvo.inscricoes_por_proponente : 1,
    limite_por_chamada: salvo?.limite_por_chamada ?? null,
    categorias: categoriasSalvas,
    cotas,
    documentos: documentosSalvos,
    nota_maxima: salvo?.nota_maxima ?? null,
    nota_corte: textoDecimal(salvo?.nota_corte),
    criterios: criteriosSalvos,
    pontuacao_extra: {
        grupos: (salvo?.pontuacao_extra?.grupos ?? []).map((grupo) => ({
            chave: novaChave(),
            grupo: grupo.grupo,
            pontos_por_pessoa: textoDecimal(grupo.pontos_por_pessoa),
            maximo: textoDecimal(grupo.maximo),
        })),
        teto: textoDecimal(salvo?.pontuacao_extra?.teto),
        conta_para_corte: salvo?.pontuacao_extra?.conta_para_corte ?? null,
    },
    informacoes: Object.fromEntries(props.secoesInformativas.map((secao) => [secao.valor, salvo?.informacoes[secao.valor] ?? ''])) as Record<string, string>,
    // Não se edita aqui: publicar e despublicar é pelo ícone da lista. Vai o estado salvo, para o servidor continuar
    // exigindo o edital completo de quem salva um já publicado.
    publicado: salvo?.publicado ?? false,
    pdf: null as File | null,
    imagem: null as File | null,
    remover_imagem: false,
});

const erros = computed<Partial<Record<string, string>>>(() => form.errors as Partial<Record<string, string>>);

// Os erros das listas chegam como "categorias.N.campo": chaves que o tipo do useForm não prevê.
// Sem campos, clearErrors() limparia todos os erros do formulário.
const limparErros = (campos: string[]): void => {
    if (campos.length === 0) return;
    form.clearErrors(...(campos as Parameters<typeof form.clearErrors>));
};

const definirErro = (campo: string, mensagem: string): void => {
    form.setError(campo as Parameters<typeof form.clearErrors>[number], mensagem);
};

// Limites do servidor (max em KB na validação): o arquivo grande nem sai do navegador.
const LIMITES = { pdf: 20 * 1024 * 1024, imagem: 5 * 1024 * 1024 } as const;
const MENSAGENS_LIMITE = { pdf: 'O PDF deve ter no máximo 20 MB.', imagem: 'A imagem deve ter no máximo 5 MB.' } as const;

const escolherArquivo = (arquivo: File | null, campo: 'pdf' | 'imagem'): void => {
    form.clearErrors(campo);

    if (arquivo && arquivo.size > LIMITES[campo]) {
        form[campo] = null;
        form.setError(campo, MENSAGENS_LIMITE[campo]);
        return;
    }
    form[campo] = arquivo;
    if (campo === 'imagem' && arquivo) form.remover_imagem = false;
};

// Uma aba por bloco do edital. Campo terminado em ".*" vale para todo erro que começa com o prefixo ("chamadas.0.nome").
const DEFINICAO_ABAS: AbaFormulario[] = [
    { id: 'identificacao', rotulo: 'Identificação', campos: ['numero', 'tipo', 'titulo', 'objeto', 'dom_data', 'dom_numero', 'categoria'] },
    { id: 'inscricao', rotulo: 'Períodos', campos: ['chamadas', 'chamadas.*', 'forma_inscricao_original'] },
    { id: 'participacao', rotulo: 'Participação', campos: ['naturezas', 'naturezas.*', 'aceita_coletivos', 'inscricoes_por_proponente', 'limite_por_chamada'] },
    { id: 'categorias', rotulo: 'Categorias', campos: ['categorias', 'categorias.*'] },
    { id: 'cotas', rotulo: 'Cotas', campos: ['cotas', 'cotas.*'] },
    { id: 'documentos', rotulo: 'Documentos', campos: ['documentos', 'documentos.*'] },
    { id: 'avaliacao', rotulo: 'Avaliação', campos: ['nota_maxima', 'nota_corte', 'criterios', 'criterios.*', 'pontuacao_extra', 'pontuacao_extra.*'] },
    { id: 'informacoes', rotulo: 'Informações', campos: ['informacoes', 'informacoes.*'] },
    { id: 'arquivos', rotulo: 'Arquivos', campos: ['pdf'] },
    // Tudo o que define o card do portal (imagem, descrição e a prévia) fica na Publicação. O questionário tem a sua
    // tela; a pendência dele (pergunta sem vínculo) impede publicar e aparece aqui.
    { id: 'publicacao', rotulo: 'Publicação', campos: ['imagem', 'remover_imagem', 'resumo', 'questionario'] },
];

// AbasFormulario conta os erros pelos campos da aba: aqui eles são as chaves com erro que caem na aba.
const abas = computed<AbaFormulario[]>(() => {
    const comErro = Object.keys(erros.value).filter((chave) => Boolean(erros.value[chave]));

    return DEFINICAO_ABAS.map((aba) => ({
        ...aba,
        campos: comErro.filter((chave) => aba.campos.some((campo) => (campo.endsWith('.*') ? chave.startsWith(campo.slice(0, -1)) : chave === campo))),
    }));
});

// "?aba=" abre o formulário numa aba (a tela do questionário manda para Categorias quando o edital não tem nenhuma).
const abaDaUrl = new URLSearchParams(window.location.search).get('aba');
const abaAtiva = ref<string>(DEFINICAO_ABAS.some((aba) => aba.id === abaDaUrl) ? (abaDaUrl as string) : 'identificacao');

// Do quadro "Importado pela IA": abre a aba do bloco e leva o foco (e a rolagem) até ela.
const irParaAba = async (aba: string): Promise<void> => {
    abaAtiva.value = aba;
    await nextTick();
    const botao = document.getElementById(`edital-aba-${aba}`);
    botao?.scrollIntoView({ block: 'nearest' });
    botao?.focus();
};

// Com erro, abre a primeira aba que tem algum e leva o foco ao campo inválido dela (os cartões com erro já se abriram).
const mostrarPrimeiroErro = (): void => {
    const comErro = abas.value.find((aba) => aba.campos.length > 0);
    if (comErro) abaAtiva.value = comErro.id;
    focarPrimeiroErro(`#edital-painel-${abaAtiva.value}`);
};

// Item removido deixa de valer nas listas que o referem; reordenar não mexe na ligação (é pela chave).
// Os erros dessas referências apontam para posições, que mudaram: saem.
const vigiarReferencias = (chaves: () => string[], aoRemover: (existentes: Set<string>) => void, errosDasReferencias: RegExp): void => {
    watch(chaves, (atuais, anteriores) => {
        const existentes = new Set(atuais);
        if (anteriores.some((chave) => !existentes.has(chave))) aoRemover(existentes);

        const vencidos = Object.keys(erros.value).filter((chave) => errosDasReferencias.test(chave));
        if (vencidos.length > 0) limparErros(vencidos);
    });
};

vigiarReferencias(
    () => form.chamadas.map((chamada) => chamada.chave),
    (existentes) => {
        for (const categoria of form.categorias) {
            if (categoria.chamada !== null && !existentes.has(categoria.chamada)) categoria.chamada = null;
        }
    },
    /^categorias\.\d+\.chamada$/,
);

vigiarReferencias(
    () => form.categorias.map((categoria) => categoria.chave),
    (existentes) => {
        for (const item of [...form.documentos, ...form.criterios]) {
            if (item.categorias.some((chave) => !existentes.has(chave))) item.categorias = item.categorias.filter((chave) => existentes.has(chave));
        }
    },
    /^(documentos|criterios)\.\d+\.categorias(\.\d+)?$/,
);

/** Período do edital como o servidor calcula: menor início e maior fim das chamadas com as duas datas (aaaa-mm-dd). */
const limitesDoPeriodo = computed<{ inicio: string; fim: string }>(() => {
    const completas = form.chamadas.filter((chamada) => chamada.inicio && chamada.fim);

    return {
        inicio: completas.map((chamada) => chamada.inicio).sort()[0] ?? '',
        fim: completas.map((chamada) => chamada.fim).sort().at(-1) ?? '',
    };
});

const periodo = computed<string | null>(() => {
    const { inicio, fim } = limitesDoPeriodo.value;
    if (!inicio) return null;

    const data = (texto: string): string => texto.split('-').reverse().join('/');

    return `${data(inicio)} a ${data(fim)}`;
});

type DadosDoFormulario = ReturnType<typeof form.data>;

const cotasPreenchidas = (dados: CotasEditavel): boolean =>
    Object.values(dados.modalidades).some((cota) => cota.ativa || cota.percentual !== '' || cota.quantidade !== '') ||
    dados.base !== '' ||
    dados.alcance !== '' ||
    dados.veda_negro_indigena !== null ||
    dados.heteroidentificacao !== null;

// Envio no contrato do SalvarEditalRequest: com o marcador, as listas vão inteiras (ausente = nenhuma);
// sem as chaves da tela; referências entre listas viram posições na lista enviada; decimais viram números.
// Cotas que nunca foram preenchidas continuam vazias (editais antigos não ganham cotas "não oferecidas").
const paraEnvio = (dados: DadosDoFormulario): Record<string, unknown> => {
    const posicaoDaChamada = posicoes(dados.chamadas);
    const posicaoDaCategoria = posicoes(dados.categorias);

    return {
        com_listas: true,
        numero: dados.numero,
        tipo: dados.tipo,
        titulo: dados.titulo,
        objeto: dados.objeto,
        dom_data: dados.dom_data,
        dom_numero: dados.dom_numero,
        categoria: dados.categoria,
        resumo: dados.resumo,
        forma_inscricao_original: dados.forma_inscricao_original,
        naturezas: dados.naturezas,
        aceita_coletivos: dados.aceita_coletivos,
        inscricoes_por_proponente: dados.inscricoes_por_proponente,
        limite_por_chamada: dados.limite_por_chamada,
        nota_maxima: dados.nota_maxima,
        nota_corte: numeroDecimal(dados.nota_corte),
        publicado: dados.publicado,
        pdf: dados.pdf,
        imagem: dados.imagem,
        remover_imagem: dados.remover_imagem,
        chamadas: dados.chamadas.map(({ id, nome, inicio, fim, validacao_inicio, validacao_fim, homologacao_inicio, homologacao_fim }) => ({
            id,
            nome,
            inicio,
            fim,
            validacao_inicio: validacao_inicio || null,
            validacao_fim: validacao_fim || null,
            homologacao_inicio: homologacao_inicio || null,
            homologacao_fim: homologacao_fim || null,
        })),
        categorias: dados.categorias.map(({ chave: _chave, chamada, itens, ...categoria }) => ({
            ...categoria,
            chamada: chamada === null ? null : (posicaoDaChamada.get(chamada) ?? null),
            itens: itens.map(({ chave: _chaveDoItem, ...item }) => item),
        })),
        documentos: dados.documentos.map(({ chave: _chave, categorias, modelo, modeloUrl: _url, modeloNome: _nome, ...documento }) => ({
            ...documento,
            categorias: documento.todas_categorias ? [] : emPosicoes(categorias, posicaoDaCategoria),
            ...(modelo ? { modelo } : {}),
        })),
        criterios: dados.criterios.map(({ chave: _chave, categorias, peso, pontos_maximos, ...criterio }) => ({
            ...criterio,
            peso: numeroDecimal(peso),
            pontos_maximos: numeroDecimal(pontos_maximos),
            categorias: criterio.todas_categorias ? [] : emPosicoes(categorias, posicaoDaCategoria),
        })),
        cotas:
            cotasSalvas !== null || cotasPreenchidas(dados.cotas)
                ? {
                      ...Object.fromEntries(
                          Object.entries(dados.cotas.modalidades).map(([modalidade, cota]) => [
                              modalidade,
                              { ativa: cota.ativa, percentual: numeroDecimal(cota.percentual), quantidade: numeroDecimal(cota.quantidade) },
                          ]),
                      ),
                      base: dados.cotas.base,
                      alcance: dados.cotas.alcance,
                      veda_negro_indigena: dados.cotas.veda_negro_indigena,
                      heteroidentificacao: dados.cotas.heteroidentificacao,
                  }
                : null,
        pontuacao_extra: {
            grupos: dados.pontuacao_extra.grupos.map((grupo) => ({
                grupo: grupo.grupo,
                pontos_por_pessoa: numeroDecimal(grupo.pontos_por_pessoa),
                maximo: numeroDecimal(grupo.maximo),
            })),
            teto: numeroDecimal(dados.pontuacao_extra.teto),
            conta_para_corte: dados.pontuacao_extra.conta_para_corte,
        },
        informacoes: dados.informacoes,
    };
};

// Edição vai como POST com _method=put: PUT com multipart não chega ao PHP.
// "indices" monta chamadas[0][nome]; o padrão ("brackets") viraria chamadas[][nome] e quebraria as listas.
const salvar = (): void => {
    const opcoes = {
        forceFormData: true,
        queryStringArrayFormat: 'indices' as const,
        preserveScroll: true,
        onError: mostrarPrimeiroErro,
    };

    if (editando) {
        form.transform((dados) => ({ ...paraEnvio(dados), _method: 'put' })).post(`${BASE}/${salvo.slug}`, opcoes);
    } else {
        form.transform(paraEnvio).post(BASE, opcoes);
    }
};

// Ao salvar um edital já publicado, o servidor exige título, descrição do card (resumo), categoria, o PDF e a imagem
// da vitrine (os enviados agora ou os já salvos) e o edital completo. Tudo volta de uma vez: cada pendência no campo da
// sua aba (chamadas, naturezas, categorias; a do questionário, que tem a sua tela, na Publicação), para todas as abas
// que faltam ficarem marcadas. Rascunho salva incompleto; o que falta para publicar aparece no ícone Publicar da lista.
const pdfObrigatorio = computed<boolean>(() => form.publicado && !salvo?.pdfUrl);
const imagemObrigatoria = computed<boolean>(() => form.publicado && !(salvo?.imagemUrl && !form.remover_imagem));

// Imagem que a prévia do card mostra: a escolhida agora ou a salva, se não for removida.
const imagemSalvaNaPrevia = computed<string | null>(() => (form.remover_imagem ? null : (salvo?.imagemUrl ?? null)));
const completoParaPublicar = computed<boolean>(() => form.publicado);

const textoBotao = computed(() => {
    if (!form.processing) return 'Salvar edital';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Salvando…';
});

const limparNaturezas = (): void => limparErros(Object.keys(erros.value).filter((chave) => chave === 'naturezas' || chave.startsWith('naturezas.')));

// Ícones das seções (traço 1.8, 24×24).
const ICONES: Record<string, string> = {
    identificacao: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    inscricao: 'M4 5h16v16H4zM4 10h16M9 3v4M15 3v4',
    participacao: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 12 0v1M16 3.5a4 4 0 0 1 0 7M18 14a6 6 0 0 1 4 6v1',
    categorias: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    cotas: 'M12 3v18M5 7h14M5 7l-3 7a4 4 0 0 0 6 0zM19 7l-3 7a4 4 0 0 0 6 0z',
    documentos: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13l2 2 4-4',
    avaliacao: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z',
    informacoes: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01',
    arquivos: 'M12 16V4M7 9l5-5 5 5M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3',
    publicacao: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
};

const tituloDaPagina = editando ? salvo.titulo || 'Edital sem título' : 'Novo edital';

/** Trilha e título da aba do navegador: "Visualizar edital", "Editar edital" ou "Novo edital". */
const modo = props.somenteLeitura ? 'Visualizar' : editando ? 'Editar' : 'Novo';

// A tela do questionário só abre com categoria gravada (as perguntas se vinculam a categorias).
const urlDoQuestionario = salvo && salvo.categorias.length > 0 ? `${BASE}/${salvo.slug}/questionario` : null;
</script>

<template>
    <!-- Na largura das listas: as abas laterais ocupam uma coluna. -->
    <PaginaRetaguarda :titulo="tituloDaPagina" :trilha="[{ texto: modo }]">
        <Head :title="`${modo} edital · Retaguarda`" />

        <template v-if="somenteLeitura" #detalhe>
            <span class="selo-faixa">Somente visualização</span>
        </template>

        <template v-if="somenteLeitura && salvo" #acoes>
            <Link
                :href="`${BASE}/${salvo.slug}/edit`"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <IconeAcao nome="editar" />
                Editar edital
            </Link>
        </template>

        <QuadroImportacao
            v-if="salvo && importacao"
            :importacao="importacao"
            :url-conferida="`${BASE}/${salvo.slug}/importacao/conferida`"
            :abas="DEFINICAO_ABAS.map((aba) => aba.id)"
            @ir-para-aba="irParaAba"
        />

        <form id="formulario-edital" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <AbasFormulario id="edital" v-model="abaAtiva" variante="lateral" :abas="abas" :erros="erros" :somente-leitura="somenteLeitura">
                <template v-for="(aba, indice) in DEFINICAO_ABAS" :key="aba.id" #[aba.id]>
                    <SecaoCadastro :id="`secao-edital-${aba.id}`" :numero="indice + 1" :titulo="aba.rotulo">
                        <template #icone>
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="ICONES[aba.id]" />
                            </svg>
                        </template>

                        <AvisoCamposObrigatorios v-if="!somenteLeitura" class="mb-5" />

                        <div v-if="aba.id === 'identificacao'" class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                            <CampoCadastro id="numero" rotulo="Número" :erro="erros.numero" v-slot="{ descricao, invalido }">
                                <input
                                    id="numero"
                                    v-model="form.numero"
                                    type="text"
                                    maxlength="30"
                                    placeholder="Ex.: 007/2026"
                                    class="campo-retaguarda"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @input="form.clearErrors('numero')"
                                />
                            </CampoCadastro>

                            <CampoCadastro id="tipo" rotulo="Tipo" :erro="erros.tipo" v-slot="{ descricao, invalido }">
                                <select
                                    id="tipo"
                                    v-model="form.tipo"
                                    class="campo-retaguarda"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @change="form.clearErrors('tipo')"
                                >
                                    <option value="">Não informado</option>
                                    <option v-for="tipo in tiposEdital" :key="tipo.valor" :value="tipo.valor">{{ tipo.rotulo }}</option>
                                </select>
                            </CampoCadastro>

                            <div class="md:col-span-2">
                                <CampoCadastro
                                    id="titulo"
                                    rotulo="Título"
                                    :obrigatorio="form.publicado"
                                    ajuda="Obrigatório para publicar."
                                    :erro="erros.titulo"
                                    v-slot="{ descricao, invalido }"
                                >
                                    <input
                                        id="titulo"
                                        v-model="form.titulo"
                                        type="text"
                                        maxlength="255"
                                        :required="form.publicado"
                                        class="campo-retaguarda"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="form.clearErrors('titulo')"
                                    />
                                </CampoCadastro>
                            </div>

                            <div class="md:col-span-2">
                                <CampoCadastro id="objeto" rotulo="Objeto" ajuda="Em poucas linhas, o que o edital seleciona." :erro="erros.objeto" v-slot="{ descricao, invalido }">
                                    <textarea
                                        id="objeto"
                                        v-model="form.objeto"
                                        rows="3"
                                        maxlength="1000"
                                        :class="classeTexto"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="form.clearErrors('objeto')"
                                    />
                                </CampoCadastro>
                            </div>

                            <CampoCadastro id="dom_data" rotulo="Publicação no DOM" :erro="erros.dom_data" v-slot="{ descricao, invalido }">
                                <input
                                    id="dom_data"
                                    v-model="form.dom_data"
                                    type="date"
                                    class="campo-retaguarda"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @input="form.clearErrors('dom_data')"
                                />
                            </CampoCadastro>

                            <CampoCadastro id="dom_numero" rotulo="Edição do DOM" :erro="erros.dom_numero" v-slot="{ descricao, invalido }">
                                <input
                                    id="dom_numero"
                                    v-model="form.dom_numero"
                                    type="text"
                                    maxlength="20"
                                    placeholder="Ex.: 8.123"
                                    class="campo-retaguarda"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @input="form.clearErrors('dom_numero')"
                                />
                            </CampoCadastro>

                            <div class="md:col-span-2">
                                <CampoCadastro
                                    id="categoria"
                                    rotulo="Categoria da vitrine"
                                    :obrigatorio="form.publicado"
                                    ajuda="Área mostrada no portal. Obrigatória para publicar."
                                    :erro="erros.categoria"
                                    v-slot="{ descricao, invalido }"
                                >
                                    <input
                                        id="categoria"
                                        v-model="form.categoria"
                                        type="text"
                                        maxlength="255"
                                        :required="form.publicado"
                                        placeholder="Ex.: Fomento, Audiovisual"
                                        class="campo-retaguarda"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="form.clearErrors('categoria')"
                                    />
                                </CampoCadastro>
                            </div>
                        </div>

                        <div v-else-if="aba.id === 'inscricao'" class="flex flex-col gap-6">
                            <p class="rounded-[16px] bg-nevoa px-4 py-3 text-[0.9375rem] leading-[1.6]">
                                <span class="font-semibold">Período de inscrição do edital:</span>
                                {{ periodo ?? 'definido pelas chamadas' }}.
                                <span class="block text-[0.8125rem] text-tinta-suave">Calculado ao salvar: o menor início e o maior fim das chamadas.</span>
                            </p>

                            <ChamadasEdital v-model="form.chamadas" :erros="erros" @limpar-erros="limparErros" />

                            <CampoCadastro
                                id="forma_inscricao_original"
                                rotulo="Forma de inscrição no edital original"
                                ajuda="Informativo (ex.: o link do formulário); a inscrição passa a ser pelo portal."
                                :erro="erros.forma_inscricao_original"
                                v-slot="{ descricao, invalido }"
                            >
                                <textarea
                                    id="forma_inscricao_original"
                                    v-model="form.forma_inscricao_original"
                                    rows="2"
                                    maxlength="1000"
                                    :class="classeTexto"
                                    :aria-invalid="invalido || undefined"
                                    :aria-describedby="descricao"
                                    @input="form.clearErrors('forma_inscricao_original')"
                                />
                            </CampoCadastro>
                        </div>

                        <div v-else-if="aba.id === 'participacao'" class="@container grid gap-x-6 gap-y-5">
                            <fieldset class="min-w-0" :aria-describedby="['naturezas-ajuda', erros.naturezas && 'naturezas-erro'].filter(Boolean).join(' ')">
                                <legend class="text-[0.9375rem] font-semibold">
                                    Naturezas aceitas<span v-if="completoParaPublicar" aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                                </legend>
                                <p id="naturezas-ajuda" :class="[classeDica, 'mt-1']">Quem pode se inscrever. Ao menos uma para publicar.</p>
                                <div class="mt-1.5 grid gap-x-6 @lg:grid-cols-2">
                                    <label v-for="natureza in naturezas" :key="natureza.valor" :class="classeMarcacao">
                                        <input
                                            v-model="form.naturezas"
                                            type="checkbox"
                                            :value="natureza.valor"
                                            :required="completoParaPublicar && form.naturezas.length === 0"
                                            :class="classeCaixa"
                                            :aria-invalid="erros.naturezas ? true : undefined"
                                            @change="limparNaturezas"
                                        />
                                        {{ natureza.rotulo }}
                                    </label>
                                </div>
                                <p v-if="erros.naturezas" id="naturezas-erro" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">{{ erros.naturezas }}</p>
                            </fieldset>

                            <div class="grid gap-x-6 gap-y-5 @2xl:grid-cols-3">
                                <CampoCadastro
                                    id="aceita_coletivos"
                                    rotulo="Aceita coletivos sem CNPJ"
                                    ajuda="Por meio de um proponente que os represente."
                                    :erro="erros.aceita_coletivos"
                                    v-slot="{ descricao, invalido }"
                                >
                                    <select
                                        id="aceita_coletivos"
                                        v-model="form.aceita_coletivos"
                                        class="campo-retaguarda"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @change="form.clearErrors('aceita_coletivos')"
                                    >
                                        <option v-for="opcao in OPCOES_SIM_NAO" :key="String(opcao.valor)" :value="opcao.valor">{{ opcao.rotulo }}</option>
                                    </select>
                                </CampoCadastro>

                                <CampoCadastro id="inscricoes_por_proponente" rotulo="Inscrições por proponente" :erro="erros.inscricoes_por_proponente" v-slot="{ descricao, invalido }">
                                    <input
                                        id="inscricoes_por_proponente"
                                        :value="form.inscricoes_por_proponente ?? ''"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        class="campo-retaguarda tabular-nums"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            form.inscricoes_por_proponente = inteiro($event, 1000);
                                            form.clearErrors('inscricoes_por_proponente');
                                        "
                                    />
                                </CampoCadastro>

                                <CampoCadastro id="limite_por_chamada" rotulo="O limite vale por chamada" :erro="erros.limite_por_chamada" v-slot="{ descricao, invalido }">
                                    <select
                                        id="limite_por_chamada"
                                        v-model="form.limite_por_chamada"
                                        class="campo-retaguarda"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @change="form.clearErrors('limite_por_chamada')"
                                    >
                                        <option v-for="opcao in OPCOES_SIM_NAO" :key="String(opcao.valor)" :value="opcao.valor">{{ opcao.rotulo }}</option>
                                    </select>
                                </CampoCadastro>
                            </div>
                        </div>

                        <CategoriasEdital
                            v-else-if="aba.id === 'categorias'"
                            v-model="form.categorias"
                            :erros="erros"
                            :naturezas="naturezas"
                            :chamadas="form.chamadas"
                            :travadas="criteriosTravados"
                            :aviso-travadas="avisoCategoriasTravadas"
                            @limpar-erros="limparErros"
                        />

                        <CotasEdital
                            v-else-if="aba.id === 'cotas'"
                            v-model="form.cotas"
                            :erros="erros"
                            :modalidades-cota="modalidadesCota"
                            :bases-cota="basesCota"
                            :alcances-cota="alcancesCota"
                            @limpar-erros="limparErros"
                        />

                        <DocumentosEdital
                            v-else-if="aba.id === 'documentos'"
                            v-model="form.documentos"
                            :erros="erros"
                            :naturezas="naturezas"
                            :etapas="etapas"
                            :modalidades-cota="modalidadesCota"
                            :checagens-documento="checagensDocumento"
                            :categorias="form.categorias"
                            :contexto-edital="{ titulo: form.titulo, objeto: form.objeto, naturezas: form.naturezas }"
                            :travados="documentosTravados"
                            :aviso-travados="avisoDocumentosTravados"
                            @limpar-erros="limparErros"
                            @definir-erro="definirErro"
                        />

                        <div v-else-if="aba.id === 'avaliacao'" class="flex flex-col gap-8">
                            <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                                <CampoCadastro id="nota_maxima" rotulo="Nota máxima" :erro="erros.nota_maxima" v-slot="{ descricao, invalido }">
                                    <input
                                        id="nota_maxima"
                                        :value="form.nota_maxima ?? ''"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        class="campo-retaguarda tabular-nums"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            form.nota_maxima = inteiro($event, 1000000, true);
                                            form.clearErrors('nota_maxima');
                                        "
                                    />
                                </CampoCadastro>

                                <CampoCadastro id="nota_corte" rotulo="Nota de corte" :erro="erros.nota_corte" v-slot="{ descricao, invalido }">
                                    <input
                                        id="nota_corte"
                                        :value="form.nota_corte"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        class="campo-retaguarda tabular-nums"
                                        :aria-invalid="invalido || undefined"
                                        :aria-describedby="descricao"
                                        @input="
                                            form.nota_corte = decimal($event, 3);
                                            form.clearErrors('nota_corte');
                                        "
                                    />
                                </CampoCadastro>
                            </div>

                            <CriteriosEdital
                                v-model="form.criterios"
                                v-model:pontuacao="form.pontuacao_extra"
                                :erros="erros"
                                :categorias="form.categorias"
                                :travados="criteriosTravados"
                                :aviso-travados="avisoCriteriosTravados"
                                @limpar-erros="limparErros"
                            />
                        </div>

                        <InformacoesEdital
                            v-else-if="aba.id === 'informacoes'"
                            v-model="form.informacoes"
                            :erros="erros"
                            :secoes="secoesInformativas"
                            @limpar-erros="limparErros"
                        />

                        <div v-else-if="aba.id === 'arquivos'" class="grid gap-x-6 gap-y-6 md:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-3">
                                <CampoCadastro v-if="!somenteLeitura" id="pdf" rotulo="PDF do edital" :obrigatorio="pdfObrigatorio" :erro="erros.pdf" v-slot="{ descricao, invalido }">
                                    <CampoArquivo
                                        id="pdf"
                                        accept="application/pdf"
                                        dica="Só PDF, até 20 MB. Obrigatório para publicar"
                                        :arquivo="form.pdf"
                                        :enviado="Boolean(salvo?.pdfUrl)"
                                        :obrigatorio="pdfObrigatorio"
                                        :descricao="descricao"
                                        :invalido="invalido"
                                        @escolher="escolherArquivo($event, 'pdf')"
                                    />
                                </CampoCadastro>
                                <p v-if="salvo?.pdfUrl" class="text-[0.9375rem]">
                                    {{ somenteLeitura ? 'PDF do edital:' : 'PDF atual:' }}
                                    <a :href="salvo.pdfUrl" class="font-semibold break-all text-acao underline">{{ salvo.pdfNome }}</a>
                                </p>
                                <p v-else-if="somenteLeitura" :class="classeDica">Sem PDF do edital.</p>
                            </div>
                        </div>

                        <div v-else-if="aba.id === 'publicacao'" class="flex flex-col gap-5">
                            <p v-if="!salvo?.publicado && !somenteLeitura" :class="classeDica">
                                Depois de salvar, publique pelo ícone Publicar da lista de editais. Para publicar, o edital precisa do PDF, da imagem da
                                vitrine e da descrição do card.
                            </p>

                            <!-- O card do portal: a imagem (em todas as vitrines) e a descrição, uma embaixo da outra, e a
                                 prévia logo abaixo. -->
                            <section aria-labelledby="card-portal-titulo" class="flex flex-col gap-6 rounded-[20px] border border-hairline p-5 sm:p-6">
                                <div>
                                    <h3 id="card-portal-titulo" class="text-[1rem] font-bold">Card do portal</h3>
                                    <p class="mt-1 text-[0.875rem] leading-[1.5] text-tinta-suave">A imagem e a descrição que o edital mostra nas vitrines do portal depois de publicado.</p>
                                </div>

                                <div class="flex min-w-0 flex-col gap-3">
                                    <p v-if="somenteLeitura" :class="salvo?.imagemUrl ? 'text-[0.9375rem] font-semibold' : classeDica">
                                        {{ salvo?.imagemUrl ? 'Imagem da vitrine:' : 'Sem imagem da vitrine.' }}
                                    </p>
                                    <CampoCadastro v-else id="imagem" rotulo="Imagem da vitrine" :obrigatorio="imagemObrigatoria" :erro="erros.imagem" v-slot="{ descricao, invalido }">
                                        <CampoArquivo
                                            id="imagem"
                                            accept="image/jpeg,image/png,image/webp"
                                            dica="JPG, PNG ou WEBP, até 5 MB. Aparece em todas as vitrines do portal; obrigatória para publicar"
                                            :arquivo="form.imagem"
                                            :enviado="Boolean(salvo?.imagemUrl) && !form.remover_imagem"
                                            :obrigatorio="imagemObrigatoria"
                                            :descricao="descricao"
                                            :invalido="invalido"
                                            @escolher="escolherArquivo($event, 'imagem')"
                                        />
                                    </CampoCadastro>
                                    <div v-if="salvo?.imagemUrl && !form.imagem" class="flex items-center gap-4">
                                        <img
                                            :src="salvo.imagemUrl"
                                            alt="Imagem atual do edital"
                                            class="aspect-[4/3] w-36 shrink-0 rounded-[12px] border border-hairline object-cover"
                                            :class="{ 'opacity-40': form.remover_imagem }"
                                        />
                                        <label v-if="!somenteLeitura" :class="classeMarcacao">
                                            <input v-model="form.remover_imagem" type="checkbox" :class="classeCaixa" @change="form.clearErrors('imagem')" />
                                            Remover imagem
                                        </label>
                                    </div>
                                </div>

                                <CampoDescricaoDoCard
                                    v-model="form.resumo"
                                    :contexto="{ titulo: form.titulo, objeto: form.objeto, categoria: form.categoria }"
                                    :obrigatorio="form.publicado"
                                    :erro="erros.resumo"
                                    @limpar-erro="form.clearErrors('resumo')"
                                />

                                <div class="border-t border-hairline pt-6">
                                    <PreviaCardEdital
                                        :titulo="form.titulo"
                                        :categoria="form.categoria"
                                        :resumo="form.resumo"
                                        :inicio="limitesDoPeriodo.inicio"
                                        :fim="limitesDoPeriodo.fim"
                                        :imagem="form.imagem"
                                        :imagem-salva="imagemSalvaNaPrevia"
                                    />
                                </div>
                            </section>

                            <p v-if="erros.questionario" id="questionario-erro" role="alert" class="rounded-[12px] bg-erro-fundo px-4 py-3 text-[0.9375rem] font-semibold text-invalido-texto">
                                {{ erros.questionario }}
                                <Link v-if="urlDoQuestionario" :href="urlDoQuestionario" class="underline">Abrir o questionário</Link>
                            </p>
                        </div>
                    </SecaoCadastro>
                </template>
            </AbasFormulario>
            <!-- Só para ler não há o que salvar: volta para a lista (Editar fica no topo). -->
            <div v-if="somenteLeitura">
                <Link :href="LISTA" class="botao-secundario">Voltar para a lista</Link>
            </div>
            <BarraAcoes
                v-else
                :cancelar="LISTA"
                :salvar="textoBotao"
                :processando="form.processing"
                :com-erros="form.hasErrors"
                mensagem-erro="Confira os campos destacados nas abas marcadas."
            />
        </form>
    </PaginaRetaguarda>
</template>
