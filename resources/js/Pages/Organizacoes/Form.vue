<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../Components/CampoArquivo.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import EtapasCadastro from '../../Components/EtapasCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import SaudacaoCadastro from '../../Components/SaudacaoCadastro.vue';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import CampoBusca from '../../Components/Organizacao/CampoBusca.vue';
import CartaoRepresentante from '../../Components/Organizacao/CartaoRepresentante.vue';
import SecaoEnderecoSede from '../../Components/Organizacao/SecaoEnderecoSede.vue';
import {
    cnpjValido,
    consultar,
    formatarCep,
    formatarCnpj,
    formatarInscricaoEstadual,
    formatarInscricaoMunicipal,
    formatarTelefone,
    normalizarCnpj,
    semAcento,
} from '../../Components/Organizacao/mascaras';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type {
    DadosPublicosCnpj,
    MunicipioBusca,
    OpcaoCadastro,
    OrganizacaoFormProps,
    RepresentanteEdicao,
    RepresentanteForm,
    RespostaConsultaCnpj,
} from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<OrganizacaoFormProps>();

const salvo = props.organizacao;
const editando = salvo !== null;
const finalizado = computed(() => !!usePage().props.auth.user?.tiposCadastro.length);

const LIMITE_REPRESENTANTES = 10;
const LIMITE_LOGO = 5 * 1024 * 1024;

const novoRepresentante = (dados: Partial<RepresentanteForm> = {}): RepresentanteEdicao => ({
    cpf: dados.cpf ?? '',
    nome: dados.nome ?? '',
    tipo_documento: dados.tipo_documento ?? '',
    numero_documento: dados.numero_documento ?? '',
    cargo: dados.cargo ?? '',
    telefone: formatarTelefone(dados.telefone ?? ''),
    email: dados.email ?? '',
    origem: dados.origem ?? '',
    origem_descricao: dados.origem_descricao ?? '',
});

const representantesIniciais = salvo?.representantes.length
    ? salvo.representantes.map((representante) => novoRepresentante(representante))
    : [novoRepresentante(props.representanteInicial ?? {})];

const booleanoParaOpcao = (valor: boolean | null | undefined): '' | '1' | '0' => (valor === true ? '1' : valor === false ? '0' : '');

// Finalidade lucrativa vai como '1', '0' ou '' (nula); o FormData leva as marcações como '1'/'0'.
const form = useForm({
    cnpj: salvo?.cnpj ?? '',
    razao_social: salvo?.razao_social ?? '',
    nome_fantasia: salvo?.nome_fantasia ?? '',
    natureza_juridica: salvo?.natureza_juridica ?? '',
    finalidade_lucrativa: booleanoParaOpcao(salvo?.finalidade_lucrativa),
    mei: salvo?.mei ?? false,
    municipio_ibge: salvo?.municipio_ibge ?? '',
    inscricao_municipal: salvo?.inscricao_municipal ?? '',
    inscricao_estadual: salvo?.inscricao_estadual ?? '',
    porte: salvo?.porte ?? '',
    data_fundacao: salvo?.data_fundacao ?? '',
    telefone: formatarTelefone(salvo?.telefone ?? ''),
    logo: null as File | null,

    cep: formatarCep(salvo?.cep ?? ''),
    logradouro: salvo?.logradouro ?? '',
    numero: salvo?.numero ?? '',
    sem_numero: salvo?.sem_numero ?? false,
    complemento: salvo?.complemento ?? '',
    bairro: salvo?.bairro ?? '',
    cidade: salvo?.cidade ?? '',
    uf: salvo?.uf ?? '',

    representantes: representantesIniciais,
});

type ChaveDeErro = Parameters<typeof form.clearErrors>[0];

// Erros de representantes chegam como representantes.N.campo, chaves que o tipo do useForm não conhece.
const erros = computed(() => form.errors as Partial<Record<string, string>>);
const limparErro = (chave: string): void => {
    form.clearErrors(chave as ChaveDeErro);
};

// Campos que a pessoa já mexeu (o id de cada campo é o nome dele): os dados públicos do CNPJ não passam por cima.
const editados = new Set<string>();
const marcarEditado = (evento: Event): void => {
    const id = (evento.target as HTMLElement).id;
    if (id) editados.add(id);
};

const mascarar = (
    evento: Event,
    campo: 'cnpj' | 'telefone' | 'cep' | 'inscricao_municipal' | 'inscricao_estadual',
    formatar: (valor: string) => string,
): void => {
    const entrada = evento.target as HTMLInputElement;
    entrada.value = formatar(entrada.value);
    form[campo] = entrada.value;
    form.clearErrors(campo);
};

const hoje = (() => {
    const agora = new Date();
    return `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, '0')}-${String(agora.getDate()).padStart(2, '0')}`;
})();

/* Natureza jurídica e município: listas com busca. */

const naturezaSelecionada = computed<OpcaoCadastro | null>(() => props.naturezas.find((natureza) => natureza.valor === form.natureza_juridica) ?? null);

const buscarNatureza = (termo: string): OpcaoCadastro[] => {
    const alvo = semAcento(termo);
    return props.naturezas.filter((natureza) => semAcento(natureza.rotulo).includes(alvo));
};

// Grupo 2 da tabela da Receita (entidades empresariais) tem finalidade lucrativa; os demais, não.
const escolherNatureza = (opcao: OpcaoCadastro | null): void => {
    form.natureza_juridica = opcao?.valor ?? '';
    form.clearErrors('natureza_juridica');
    if (opcao) form.finalidade_lucrativa = opcao.valor.startsWith('2') ? '1' : '0';
};

const opcaoMunicipio = (municipio: MunicipioBusca): OpcaoCadastro => ({ valor: municipio.codigo, rotulo: `${municipio.nome} – ${municipio.uf}` });

const municipioSelecionado = ref<OpcaoCadastro | null>(salvo?.municipio ? opcaoMunicipio(salvo.municipio) : null);

// Só a partir de 3 letras e 300 ms depois da última tecla: o limite de consultas é dividido com CNPJ e CEP.
const buscarMunicipio = async (termo: string, sinal: AbortSignal): Promise<OpcaoCadastro[] | null> => {
    const municipios = await consultar<MunicipioBusca[]>(`/cadastro/consultas/municipios?busca=${encodeURIComponent(termo)}`, sinal);
    return Array.isArray(municipios) ? municipios.map(opcaoMunicipio) : null;
};

const escolherMunicipio = (opcao: OpcaoCadastro | null): void => {
    municipioSelecionado.value = opcao;
    form.municipio_ibge = opcao?.valor ?? '';
    form.clearErrors('municipio_ibge');
};

/* Dados públicos do CNPJ. */

const MENSAGEM_DUPLICADO = 'Esta Pessoa Jurídica já está cadastrada. Peça ao responsável por ela para vincular você.';

const situacaoCnpj = ref<'buscando' | 'preenchido' | null>(null);
// CNPJ cuja consulta terminou e já foi aplicada (não repete) e a consulta em andamento, se houver.
let cnpjConsultado = '';
let consultaCnpj: { cnpj: string; controle: AbortController } | null = null;

// A pessoa mudou o CNPJ: a consulta em andamento é descartada e o "Buscando…" some.
const abandonarConsultaCnpj = (): void => {
    if (consultaCnpj === null) return;
    consultaCnpj.controle.abort();
    consultaCnpj = null;
    if (situacaoCnpj.value === 'buscando') situacaoCnpj.value = null;
};

const preencherComCnpj = (dados: DadosPublicosCnpj): void => {
    // Preenche o que está vazio ou o que a pessoa ainda não mexeu.
    const pode = (campo: string, atual: string | boolean): boolean => !editados.has(campo) || atual === '' || atual === false;
    const definir = <C extends 'razao_social' | 'nome_fantasia' | 'porte' | 'data_fundacao' | 'complemento' | 'bairro' | 'cidade' | 'uf' | 'logradouro'>(
        campo: C,
        valor: string | null,
    ): void => {
        if (valor !== null && pode(campo, form[campo])) {
            form[campo] = valor;
            form.clearErrors(campo);
        }
    };

    definir('razao_social', dados.razao_social);
    definir('nome_fantasia', dados.nome_fantasia);
    definir('porte', dados.porte);
    definir('data_fundacao', dados.data_fundacao);
    definir('logradouro', dados.logradouro);
    definir('complemento', dados.complemento);
    definir('bairro', dados.bairro);
    definir('cidade', dados.cidade);
    definir('uf', dados.uf);

    if (dados.natureza_juridica !== null && pode('natureza_juridica', form.natureza_juridica)) {
        form.natureza_juridica = dados.natureza_juridica;
        form.clearErrors('natureza_juridica');
    }
    if (dados.finalidade_lucrativa !== null && pode('finalidade_lucrativa', form.finalidade_lucrativa)) {
        form.finalidade_lucrativa = booleanoParaOpcao(dados.finalidade_lucrativa);
    }
    if (pode('mei', form.mei)) form.mei = dados.mei;
    if (dados.telefone !== null && pode('telefone', form.telefone)) form.telefone = formatarTelefone(dados.telefone);
    if (dados.cep !== null && pode('cep', form.cep)) form.cep = formatarCep(dados.cep);
    if (pode('numero', form.numero) && pode('sem_numero', form.sem_numero)) {
        if (dados.sem_numero) {
            form.sem_numero = true;
            form.numero = '';
        } else if (dados.numero !== null) {
            form.sem_numero = false;
            form.numero = dados.numero;
        }
    }
    if (dados.municipio !== null && pode('municipio_ibge', form.municipio_ibge)) escolherMunicipio(opcaoMunicipio(dados.municipio));
};

// Ao sair do campo. Falha, 429 ou CNPJ fora das bases públicas (o alfanumérico, por exemplo): segue à mão.
const consultarCnpj = async (): Promise<void> => {
    const cnpj = normalizarCnpj(form.cnpj);
    if (cnpj.length !== 14 || cnpj === cnpjConsultado || cnpj === consultaCnpj?.cnpj) return;

    if (!cnpjValido(cnpj)) {
        form.setError('cnpj', 'CNPJ inválido. Confira os números e letras.');
        return;
    }

    abandonarConsultaCnpj();
    const consulta = { cnpj, controle: new AbortController() };
    consultaCnpj = consulta;
    situacaoCnpj.value = 'buscando';
    const resposta = await consultar<RespostaConsultaCnpj>(`/cadastro/consultas/cnpj/${cnpj}`, consulta.controle.signal);

    // Abandonada ou substituída enquanto corria: quem a substituiu já cuidou do aviso.
    if (consultaCnpj !== consulta) return;
    consultaCnpj = null;
    situacaoCnpj.value = null;

    if (resposta === null) return;
    if ('jaCadastrado' in resposta) {
        form.setError('cnpj', MENSAGEM_DUPLICADO);
        return;
    }

    cnpjConsultado = cnpj;
    if (resposta.encontrado) {
        preencherComCnpj(resposta.dados);
        situacaoCnpj.value = 'preenchido';
    }
};

const digitarCnpj = (evento: Event): void => {
    mascarar(evento, 'cnpj', formatarCnpj);
    const cnpj = normalizarCnpj(form.cnpj);
    if (cnpj !== consultaCnpj?.cnpj) abandonarConsultaCnpj();
    if (cnpj !== cnpjConsultado) {
        cnpjConsultado = '';
        if (situacaoCnpj.value === 'preenchido') situacaoCnpj.value = null;
    }
};

/* Logo. */

const escolherLogo = (arquivo: File | null): void => {
    form.clearErrors('logo');
    if (arquivo && arquivo.size > LIMITE_LOGO) {
        form.logo = null;
        form.setError('logo', 'A imagem deve ter no máximo 5 MB.');
        return;
    }
    form.logo = arquivo;
};

/* Representantes. */

let proximaChave = 0;
const chaves = ref<number[]>(form.representantes.map(() => proximaChave++));

const limparErrosDeRepresentantes = (): void => {
    const chavesErro = Object.keys(form.errors).filter((chave) => chave.startsWith('representantes'));
    chavesErro.forEach(limparErro);
};

const adicionarRepresentante = (): void => {
    if (form.representantes.length >= LIMITE_REPRESENTANTES) return;
    form.representantes.push(novoRepresentante());
    chaves.value.push(proximaChave++);
    limparErro('representantes');
    const indice = form.representantes.length - 1;
    nextTick(() => document.getElementById(`representante-${indice}-cpf`)?.focus());
};

// Os índices dos erros mudariam de dono; somem com a remoção e voltam no próximo envio.
const removerRepresentante = (indice: number): void => {
    if (form.representantes.length <= 1) return;
    form.representantes.splice(indice, 1);
    chaves.value.splice(indice, 1);
    limparErrosDeRepresentantes();
    nextTick(() => document.getElementById(`representante-${Math.max(0, indice - 1)}`)?.focus());
};

/* Envio. */

const salvar = (): void => {
    const opcoes = {
        preserveScroll: true,
        forceFormData: true,
        // O primeiro campo com erro recebe o foco e fica no meio da tela, com o rótulo à vista.
        onError: () =>
            nextTick(() => {
                const campo = document.querySelector<HTMLElement>('#formulario-organizacao [aria-invalid="true"]');
                campo?.focus({ preventScroll: true });
                campo?.scrollIntoView({ block: 'center' });
            }),
    };

    if (salvo) {
        form.transform((dados) => ({ ...dados, _method: 'put' })).post(`/organizacoes/${salvo.id}`, opcoes);
    } else {
        form.post('/organizacoes', opcoes);
    }
};

const textoBotao = computed(() => {
    if (!form.processing) return 'Salvar Pessoa Jurídica';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Salvando…';
});

const titulo = editando ? 'Editar Pessoa Jurídica' : 'Cadastrar Pessoa Jurídica';

const classeCampo =
    'h-12 w-full rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 text-base text-home-tinta placeholder:text-tinta-suave disabled:cursor-not-allowed disabled:bg-nevoa disabled:opacity-70';
const classeSomenteLeitura =
    'h-12 w-full cursor-default rounded-[12px] border border-hairline bg-nevoa px-4 text-base font-semibold text-home-tinta tabular-nums';
const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <div>
        <Head :title="titulo" />

        <FaixaPortal aria-labelledby="organizacao-titulo">
            <!-- Primeira Pessoa Jurídica no modo edição do cadastro: o mesmo cabeçalho das outras etapas. -->
            <SaudacaoCadastro v-if="finalizado && !editando && noCadastroInicial" id-titulo="organizacao-titulo" />
            <div v-else class="flex flex-col gap-3">
                <Link
                    v-if="editando || !noCadastroInicial"
                    href="/organizacoes"
                    class="inline-flex min-h-11 items-center gap-2 self-start text-[0.8125rem] font-semibold tracking-[0.12em] text-white/85 uppercase no-underline hover:text-white"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    Voltar para Minhas Pessoas Jurídicas
                </Link>
                <p v-else class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Etapa 4 de 5 · Pessoa Jurídica</p>
                <h1 id="organizacao-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    {{ titulo }}
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    {{ editando ? 'Atualize os dados da Pessoa Jurídica e dos representantes legais.' : 'Informe o CNPJ e confira os dados da Pessoa Jurídica que você representa.' }}
                </p>
            </div>
            <template v-if="!editando && noCadastroInicial" #abaixo>
                <EtapasCadastro :atual="4" :navegavel="finalizado" />
            </template>
        </FaixaPortal>

        <form
            id="formulario-organizacao"
            novalidate
            class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24"
            @input="marcarEditado"
            @submit.prevent="salvar"
        >
            <SecaoCadastro id="secao-dados-institucionais" :numero="1" titulo="Dados institucionais">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h1M14 10h1M9 14h1M14 14h1M10 21v-3h4v3" />
                    </svg>
                </template>

                <!-- Dentro do primeiro cartão: o formulário sobe sobre a faixa escura, onde o texto do aviso não seria legível. -->
                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <div class="flex flex-col gap-2 md:col-span-2">
                        <CampoCadastro
                            v-if="editando"
                            id="cnpj"
                            rotulo="CNPJ"
                            obrigatorio
                            ajuda="O CNPJ não pode ser alterado depois de salvo."
                            v-slot="{ descricao }"
                        >
                            <input id="cnpj" :value="form.cnpj" type="text" readonly :class="[classeSomenteLeitura, 'md:max-w-sm']" :aria-describedby="descricao" />
                        </CampoCadastro>
                        <CampoCadastro
                            v-else
                            id="cnpj"
                            rotulo="CNPJ"
                            obrigatorio
                            ajuda="Aceita o CNPJ numérico e o alfanumérico (IN RFB 2.229/2024)."
                            :erro="form.errors.cnpj"
                            v-slot="{ descricao, invalido }"
                        >
                            <input
                                id="cnpj"
                                :value="form.cnpj"
                                type="text"
                                autocapitalize="characters"
                                autocomplete="off"
                                spellcheck="false"
                                maxlength="18"
                                required
                                placeholder="00.000.000/0000-00"
                                :class="[classeCampo, 'tabular-nums uppercase md:max-w-sm']"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="digitarCnpj"
                                @blur="consultarCnpj"
                            />
                        </CampoCadastro>

                        <p aria-live="polite" class="text-[0.9375rem] leading-[1.5]">
                            <span v-if="situacaoCnpj === 'buscando'" class="inline-flex items-center gap-2 text-tinta-suave">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 animate-spin motion-reduce:animate-none" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                                    <path d="M12 3a9 9 0 1 0 9 9" />
                                </svg>
                                Buscando os dados públicos do CNPJ…
                            </span>
                            <span v-else-if="situacaoCnpj === 'preenchido'" class="flex items-start gap-2 rounded-[12px] bg-nevoa px-4 py-3 font-semibold text-acao">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="M12 11v5M12 8h.01" />
                                </svg>
                                Dados preenchidos com as informações públicas da Receita Federal. Confira antes de salvar.
                            </span>
                        </p>
                    </div>

                    <CampoCadastro id="razao_social" rotulo="Razão social" obrigatorio :erro="form.errors.razao_social" v-slot="{ descricao, invalido }">
                        <input
                            id="razao_social"
                            v-model="form.razao_social"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="Nome registrado na Receita Federal"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('razao_social')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="nome_fantasia" rotulo="Nome fantasia" :erro="form.errors.nome_fantasia" v-slot="{ descricao, invalido }">
                        <input
                            id="nome_fantasia"
                            v-model="form.nome_fantasia"
                            type="text"
                            maxlength="255"
                            placeholder="Como a Pessoa Jurídica é conhecida (opcional)"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="natureza_juridica"
                        rotulo="Natureza jurídica"
                        obrigatorio
                        ajuda="Os quatro dígitos que constam no cartão CNPJ — por exemplo, 2062 para sociedade empresária limitada."
                        :erro="form.errors.natureza_juridica"
                        v-slot="{ descricao, invalido }"
                    >
                        <CampoBusca
                            id="natureza_juridica"
                            rotulo="Naturezas jurídicas"
                            :selecionado="naturezaSelecionada"
                            :buscar="buscarNatureza"
                            placeholder="Digite o código ou a descrição"
                            texto-vazio="Nenhuma natureza jurídica encontrada."
                            obrigatorio
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherNatureza"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="finalidade_lucrativa"
                        rotulo="Finalidade lucrativa"
                        ajuda="Sugerida pela natureza jurídica. Confira e ajuste se necessário."
                        :erro="form.errors.finalidade_lucrativa"
                        v-slot="{ descricao, invalido }"
                    >
                        <select
                            id="finalidade_lucrativa"
                            v-model="form.finalidade_lucrativa"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        >
                            <option value="">Selecione…</option>
                            <option value="1">Sim</option>
                            <option value="0">Não</option>
                        </select>
                    </CampoCadastro>

                    <div class="md:col-span-2">
                        <label :class="classeMarcacao">
                            <input id="mei" v-model="form.mei" type="checkbox" :class="classeCaixa" aria-describedby="mei-ajuda" />
                            Optante pelo MEI (Simei)
                        </label>
                        <p id="mei-ajuda" class="pl-8 text-[0.8125rem] leading-[1.5] text-tinta-suave">
                            Marque se a empresa é microempreendedor individual. O edital pode exigir documentos diferentes de um MEI.
                        </p>
                    </div>

                    <CampoCadastro
                        id="municipio_ibge"
                        rotulo="Município de atuação"
                        obrigatorio
                        ajuda="Qualquer município do Brasil. Pode ser diferente da cidade da sede."
                        :erro="form.errors.municipio_ibge"
                        v-slot="{ descricao, invalido }"
                    >
                        <CampoBusca
                            id="municipio_ibge"
                            rotulo="Municípios"
                            :selecionado="municipioSelecionado"
                            :buscar="buscarMunicipio"
                            :minimo="3"
                            :atraso="300"
                            placeholder="Digite o nome do município"
                            texto-vazio="Nenhum município encontrado."
                            obrigatorio
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherMunicipio"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="porte" rotulo="Porte" :erro="form.errors.porte" v-slot="{ descricao, invalido }">
                        <select id="porte" v-model="form.porte" :class="classeCampo" :aria-invalid="invalido || undefined" :aria-describedby="descricao" @change="form.clearErrors('porte')">
                            <option value="">Selecione…</option>
                            <option v-for="opcao in opcoes.portes" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="inscricao_municipal" rotulo="Inscrição municipal" :erro="form.errors.inscricao_municipal" v-slot="{ descricao, invalido }">
                        <input
                            id="inscricao_municipal"
                            :value="form.inscricao_municipal"
                            type="text"
                            inputmode="numeric"
                            maxlength="30"
                            placeholder="000000-0"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'inscricao_municipal', formatarInscricaoMunicipal)"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="inscricao_estadual" rotulo="Inscrição estadual" :erro="form.errors.inscricao_estadual" v-slot="{ descricao, invalido }">
                        <input
                            id="inscricao_estadual"
                            :value="form.inscricao_estadual"
                            type="text"
                            inputmode="numeric"
                            maxlength="30"
                            placeholder="00.000.00-0"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'inscricao_estadual', formatarInscricaoEstadual)"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="data_fundacao"
                        rotulo="Data de fundação"
                        ajuda="Dia, mês e ano de fundação da Pessoa Jurídica."
                        :erro="form.errors.data_fundacao"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="data_fundacao"
                            v-model="form.data_fundacao"
                            type="date"
                            :max="hoje"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('data_fundacao')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="telefone" rotulo="Telefone" :erro="form.errors.telefone" v-slot="{ descricao, invalido }">
                        <input
                            id="telefone"
                            :value="form.telefone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="off"
                            maxlength="15"
                            placeholder="(00) 00000-0000"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'telefone', formatarTelefone)"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="logo" class="md:col-span-2" rotulo="Logo da Pessoa Jurídica" :erro="form.errors.logo" v-slot="{ descricao, invalido }">
                        <div class="flex items-start gap-3">
                            <img
                                v-if="salvo?.logoUrl && !form.logo"
                                :src="salvo.logoUrl"
                                alt="Logo atual da Pessoa Jurídica"
                                class="size-12 shrink-0 rounded-[12px] border border-hairline bg-superficie object-contain"
                            />
                            <div class="min-w-0 flex-1">
                                <CampoArquivo
                                    id="logo"
                                    accept="image/jpeg,image/png,image/webp"
                                    dica="JPG, PNG ou WEBP, até 5 MB"
                                    :arquivo="form.logo"
                                    :enviado="Boolean(salvo?.logoUrl)"
                                    :descricao="descricao"
                                    :invalido="invalido"
                                    @escolher="escolherLogo"
                                />
                            </div>
                        </div>
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoEnderecoSede :endereco="form" :erros="erros" :estados="opcoes.estados" @limpar-erro="limparErro" />

            <SecaoCadastro id="secao-representantes" :numero="3" titulo="Representantes legais">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="8" r="3.5" />
                        <path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6" />
                    </svg>
                </template>

                <p class="max-w-3xl text-[0.9375rem] leading-[1.6] text-tinta-suave">
                    Quem responde juridicamente pela Pessoa Jurídica. Ela pode ter mais de um; nem todo representante legal é sócio.
                </p>
                <p v-if="form.errors.representantes" role="alert" class="mt-3 text-[0.9375rem] font-semibold text-invalido-texto">{{ form.errors.representantes }}</p>

                <div class="mt-5 flex flex-col gap-4">
                    <CartaoRepresentante
                        v-for="(_, indice) in form.representantes"
                        :key="chaves[indice]"
                        v-model="form.representantes[indice]"
                        :indice="indice"
                        :total="form.representantes.length"
                        :erros="erros"
                        :tipos-documento="opcoes.tiposDocumento"
                        :origens="opcoes.origens"
                        :pode-remover="form.representantes.length > 1"
                        @remover="removerRepresentante(indice)"
                        @limpar-erro="limparErro"
                    />
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        :disabled="form.representantes.length >= LIMITE_REPRESENTANTES"
                        class="pressionavel inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 sm:w-auto text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
                        @click="adicionarRepresentante"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        Adicionar representante
                    </button>
                    <p v-if="form.representantes.length >= LIMITE_REPRESENTANTES" class="text-[0.8125rem] text-tinta-suave">
                        Limite de {{ LIMITE_REPRESENTANTES }} representantes.
                    </p>
                </div>
            </SecaoCadastro>

            <div class="flex flex-col-reverse gap-4 sm:flex-row sm:items-start sm:justify-between">
                <Link
                    :href="editando || !noCadastroInicial ? '/organizacoes' : '/cadastro/organizacao'"
                    class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill border border-tinta-suave/70 bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-home-tinta uppercase no-underline hover:border-acao hover:text-acao"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    {{ editando ? 'Cancelar' : noCadastroInicial ? 'Voltar' : 'Voltar para Minhas organizações' }}
                </Link>

                <div class="flex flex-col gap-2 sm:items-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill bg-acao px-8 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z" />
                            <path d="M8 3v5h7V3M8 21v-7h8v7" />
                        </svg>
                        {{ textoBotao }}
                    </button>
                    <p v-if="form.hasErrors" role="alert" class="text-[0.9375rem] font-semibold text-invalido-texto">Confira os campos destacados acima.</p>
                </div>
            </div>
        </form>
    </div>
</template>
