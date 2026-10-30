<script setup lang="ts">
import { computed, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../Components/CampoArquivo.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import EtapasCadastro from '../../Components/EtapasCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import Interruptor from '../../Components/Interruptor.vue';
import SaudacaoCadastro from '../../Components/SaudacaoCadastro.vue';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { ArquivoCadastro, CadastroCompletarProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<CadastroCompletarProps>();

// Com o tipo de cadastro escolhido, esta tela é a opção "Cadastro" do menu: consulta e edição.
const finalizado = computed(() => !!usePage().props.auth.user?.tiposCadastro.length);

// (71) 99999-9999 para celular e (71) 3333-3333 para fixo, enquanto a pessoa digita.
const formatarTelefone = (valor: string): string => {
    const digitos = valor.replace(/\D/g, '').slice(0, 11);
    if (digitos.length <= 2) return digitos ? `(${digitos}` : '';
    if (digitos.length <= 6) return `(${digitos.slice(0, 2)}) ${digitos.slice(2)}`;
    const corte = digitos.length === 11 ? 7 : 6;
    return `(${digitos.slice(0, 2)}) ${digitos.slice(2, corte)}-${digitos.slice(corte)}`;
};

const formatarCep = (valor: string): string => {
    const digitos = valor.replace(/\D/g, '').slice(0, 8);
    return digitos.length > 5 ? `${digitos.slice(0, 5)}-${digitos.slice(5)}` : digitos;
};

const salvo = props.cadastro;

// Nome e CPF não entram no envio; e-mail e telefone do gov.br vão, mas o servidor fica com os do gov.br.
const form = useForm({
    nome_social: salvo?.nome_social ?? '',
    nome_artistico: salvo?.nome_artistico ?? '',
    data_nascimento: salvo?.data_nascimento ?? '',
    foto_perfil: null as File | null,

    pais_origem: salvo?.pais_origem ?? 'Brasil',
    rg: salvo?.rg ?? '',
    documento_cpf: null as File | null,
    documento_rg: null as File | null,
    documento_passaporte: null as File | null,
    documento_crnm: null as File | null,
    documento_outros: null as File | null,

    drt: salvo?.drt ?? '',
    areas_atuacao: [...(salvo?.areas_atuacao ?? [])] as number[],
    cultura_principal_renda: salvo?.cultura_principal_renda ?? false,
    ja_foi_avaliador: salvo?.ja_foi_avaliador ?? false,
    interesse_avaliador: salvo?.interesse_avaliador ?? false,
    representa_coletivo: salvo?.representa_coletivo ?? false,

    telefone: formatarTelefone(props.govbr.telefone ?? salvo?.telefone ?? ''),
    telefone_adicional: formatarTelefone(salvo?.telefone_adicional ?? ''),
    email: props.govbr.email ?? salvo?.email ?? '',

    identidade_genero: salvo?.identidade_genero ?? '',
    raca_cor: salvo?.raca_cor ?? '',
    pcd: salvo?.pcd ?? false,
    deficiencia: salvo?.deficiencia ?? '',
    deficiencia_outra: salvo?.deficiencia_outra ?? '',
    escolaridade: salvo?.escolaridade ?? '',
    renda: salvo?.renda ?? '',
    povo_tradicional: salvo?.povo_tradicional ?? '',
    anos_atuacao: salvo?.anos_atuacao !== null && salvo?.anos_atuacao !== undefined ? String(salvo.anos_atuacao) : '',

    cep: formatarCep(salvo?.cep ?? ''),
    logradouro: salvo?.logradouro ?? '',
    numero: salvo?.numero ?? '',
    bairro: salvo?.bairro ?? '',
    complemento: salvo?.complemento ?? '',
    cidade: salvo?.cidade ?? '',
    estado: salvo?.estado ?? '',
    territorio: salvo?.territorio ?? '',
    comprovante_residencia: null as File | null,
});

// Área desativada com o formulário aberto: o erro do servidor traz a lista sem ela, e ela sai do que vai ser enviado
// (marcada e sem caixa na tela, não haveria como desmarcá-la).
watch(
    () => props.opcoes.areasAtuacao,
    (areas) => {
        form.areas_atuacao = form.areas_atuacao.filter((id) => areas.some((area) => area.valor === id));
    },
);

// Como no FakeLogin: atualiza o próprio campo, senão o Vue não redesenha quando a máscara descarta o que foi digitado.
const mascarar = (evento: Event, campo: 'telefone' | 'telefone_adicional' | 'cep', formatar: (valor: string) => string): void => {
    const entrada = evento.target as HTMLInputElement;
    entrada.value = formatar(entrada.value);
    form[campo] = entrada.value;
    form.clearErrors(campo);
};

// O limite do servidor é 2 MB por arquivo; acima disso o arquivo nem sai do navegador.
const LIMITE_ARQUIVO = 2 * 1024 * 1024;

const escolherArquivo = (arquivo: File | null, campo: ArquivoCadastro): void => {
    form.clearErrors(campo);

    if (arquivo && arquivo.size > LIMITE_ARQUIVO) {
        form[campo] = null;
        form.setError(campo, 'O arquivo deve ter no máximo 2 MB.');
        return;
    }
    form[campo] = arquivo;
};

const arquivoEnviado = (campo: ArquivoCadastro): boolean => props.cadastro?.arquivos[campo] ?? false;
const tiposDocumento = 'application/pdf,image/jpeg,image/png';
const dicaDocumento = 'PDF, JPG ou PNG, até 2 MB';

// Erro do servidor: o foco vai para o primeiro campo marcado, que rola para a vista.
const salvar = (): void => {
    form.post('/cadastro', {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-cadastro'),
    });
};

const textoBotao = computed(() => {
    if (!form.processing) return 'Salvar cadastro';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Salvando…';
});

const classeCampo =
    'h-12 w-full rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 text-base text-home-tinta placeholder:text-tinta-suave';
// Campos do gov.br: classes próprias, e não somadas às de classeCampo, para a cor de fundo e a borda não disputarem.
const classeSomenteLeitura =
    'h-12 w-full cursor-default rounded-[12px] border border-hairline bg-nevoa px-4 text-base font-semibold text-home-tinta tabular-nums';
</script>

<template>
    <div>
        <Head title="Complete seu cadastro" />

        <FaixaPortal aria-labelledby="portal-titulo">
            <SaudacaoCadastro id-titulo="portal-titulo" :saudacao="!finalizado">
                {{ finalizado ? 'Confira e atualize seus dados abaixo.' : 'Para continuar, complete seus dados abaixo.' }}
            </SaudacaoCadastro>
            <template #abaixo>
                <EtapasCadastro :atual="2" :navegavel="finalizado" />
            </template>
        </FaixaPortal>

        <form
            id="formulario-cadastro"
            novalidate
            class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24"
            @submit.prevent="salvar"
        >
            <SecaoCadastro id="secao-dados-pessoais" :numero="1" titulo="Dados pessoais">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 21a8 8 0 0 1 16 0" />
                    </svg>
                </template>

                <!-- Dentro do primeiro bloco: o formulário sobe sobre a faixa azul e o aviso ficaria sobre ela. -->
                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="nome" rotulo="Nome completo" obrigatorio govbr v-slot="{ descricao }">
                        <input id="nome" :value="govbr.nome" type="text" readonly :class="classeSomenteLeitura" :aria-describedby="descricao" />
                    </CampoCadastro>

                    <CampoCadastro id="nome_social" rotulo="Nome social" :erro="form.errors.nome_social" v-slot="{ descricao, invalido }">
                        <input
                            id="nome_social"
                            v-model="form.nome_social"
                            type="text"
                            maxlength="255"
                            placeholder="Digite seu nome social (opcional)"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="nome_artistico" rotulo="Nome artístico" :erro="form.errors.nome_artistico" v-slot="{ descricao, invalido }">
                        <input
                            id="nome_artistico"
                            v-model="form.nome_artistico"
                            type="text"
                            maxlength="255"
                            placeholder="Digite seu nome artístico (opcional)"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="data_nascimento" rotulo="Data de nascimento" obrigatorio :erro="form.errors.data_nascimento" v-slot="{ descricao, invalido }">
                        <input
                            id="data_nascimento"
                            v-model="form.data_nascimento"
                            type="date"
                            autocomplete="bday"
                            min="1900-01-02"
                            :max="opcoes.nascimentoMaximo"
                            required
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('data_nascimento')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="foto_perfil" rotulo="Foto de perfil" :erro="form.errors.foto_perfil" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="foto_perfil"
                            accept="image/jpeg,image/png,image/gif"
                            dica="JPG, PNG ou GIF, até 2 MB"
                            :arquivo="form.foto_perfil"
                            :enviado="arquivoEnviado('foto_perfil')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'foto_perfil')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-documentos" :numero="2" titulo="Documentos">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                        <path d="M14 3v5h5M9 13h6M9 17h6" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="cpf" rotulo="CPF" obrigatorio govbr ajuda="Confirmado pelo gov.br e não pode ser alterado." v-slot="{ descricao }">
                        <input id="cpf" :value="govbr.cpf" type="text" readonly :class="classeSomenteLeitura" :aria-describedby="descricao" />
                    </CampoCadastro>

                    <CampoCadastro id="pais_origem" rotulo="País de origem" obrigatorio ajuda="Selecione seu país de origem." :erro="form.errors.pais_origem" v-slot="{ descricao, invalido }">
                        <select
                            id="pais_origem"
                            v-model="form.pais_origem"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('pais_origem')"
                        >
                            <option v-for="pais in opcoes.paises" :key="pais" :value="pais">{{ pais }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro
                        id="documento_cpf"
                        rotulo="Cópia do CPF"
                        :obrigatorio="!arquivoEnviado('documento_cpf')"
                        :erro="form.errors.documento_cpf"
                        v-slot="{ descricao, invalido }"
                    >
                        <CampoArquivo
                            id="documento_cpf"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.documento_cpf"
                            :enviado="arquivoEnviado('documento_cpf')"
                            :obrigatorio="!arquivoEnviado('documento_cpf')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'documento_cpf')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="rg" rotulo="RG" obrigatorio :erro="form.errors.rg" v-slot="{ descricao, invalido }">
                        <input
                            id="rg"
                            v-model="form.rg"
                            type="text"
                            maxlength="20"
                            required
                            placeholder="Digite seu RG"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('rg')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="documento_rg"
                        rotulo="Cópia do RG"
                        :obrigatorio="!arquivoEnviado('documento_rg')"
                        :erro="form.errors.documento_rg"
                        v-slot="{ descricao, invalido }"
                    >
                        <CampoArquivo
                            id="documento_rg"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.documento_rg"
                            :enviado="arquivoEnviado('documento_rg')"
                            :obrigatorio="!arquivoEnviado('documento_rg')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'documento_rg')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="documento_passaporte" rotulo="Passaporte" :erro="form.errors.documento_passaporte" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="documento_passaporte"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.documento_passaporte"
                            :enviado="arquivoEnviado('documento_passaporte')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'documento_passaporte')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="documento_crnm" rotulo="Registro Nacional Migratório (CRNM)" :erro="form.errors.documento_crnm" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="documento_crnm"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.documento_crnm"
                            :enviado="arquivoEnviado('documento_crnm')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'documento_crnm')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="documento_outros" rotulo="Outros documentos" :erro="form.errors.documento_outros" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="documento_outros"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.documento_outros"
                            :enviado="arquivoEnviado('documento_outros')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'documento_outros')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-profissional" :numero="3" titulo="Informações profissionais">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="7" width="18" height="13" rx="2" />
                        <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="drt" rotulo="Registro profissional (DRT)" :erro="form.errors.drt" v-slot="{ descricao, invalido }">
                        <input
                            id="drt"
                            v-model="form.drt"
                            type="text"
                            maxlength="30"
                            placeholder="Digite seu DRT"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="areas_atuacao"
                        class="md:col-span-2"
                        grupo
                        rotulo="Áreas de atuação"
                        obrigatorio
                        ajuda="Marque todas em que você atua."
                        :erro="form.errors.areas_atuacao"
                        v-slot="{ invalido }"
                    >
                        <!-- Em colunas, e não em grade: a ordem alfabética corre de cima para baixo, coluna a coluna. -->
                        <div class="gap-x-4 rounded-[12px] border border-hairline p-2 sm:columns-2 lg:columns-3" :class="{ 'campo-invalido': invalido }">
                            <label
                                v-for="area in opcoes.areasAtuacao"
                                :key="area.valor"
                                class="flex min-h-11 cursor-pointer break-inside-avoid items-center gap-3 rounded-[8px] px-2 py-1.5 hover:bg-nevoa"
                            >
                                <input
                                    v-model="form.areas_atuacao"
                                    type="checkbox"
                                    name="areas_atuacao"
                                    :value="area.valor"
                                    class="size-5 shrink-0 cursor-pointer accent-acao"
                                    :aria-invalid="invalido || undefined"
                                    @change="form.clearErrors('areas_atuacao')"
                                />
                                <span class="min-w-0 break-words">{{ area.rotulo }}</span>
                            </label>
                        </div>
                    </CampoCadastro>

                    <fieldset class="md:col-span-2">
                        <legend class="text-[0.9375rem] font-semibold">Marque o que se aplica a você</legend>
                        <div class="mt-1 grid gap-x-6 md:grid-cols-2">
                            <Interruptor id="cultura_principal_renda" v-model="form.cultura_principal_renda" rotulo="A atividade cultural é minha principal fonte de renda" variante="linha" />
                            <Interruptor id="ja_foi_avaliador" v-model="form.ja_foi_avaliador" rotulo="Já atuei como avaliador(a) em editais culturais" variante="linha" />
                            <Interruptor id="interesse_avaliador" v-model="form.interesse_avaliador" rotulo="Tenho interesse em ser avaliador(a)" variante="linha" />
                            <Interruptor id="representa_coletivo" v-model="form.representa_coletivo" rotulo="Sou representante de grupo ou coletivo cultural" variante="linha" />
                        </div>
                    </fieldset>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-contato" :numero="4" titulo="Informações de contato">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="m3 7 9 6 9-6" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="telefone" rotulo="Telefone" obrigatorio :govbr="!!govbr.telefone" :erro="form.errors.telefone" v-slot="{ descricao, invalido }">
                        <input
                            v-if="govbr.telefone"
                            id="telefone"
                            :value="form.telefone"
                            type="tel"
                            readonly
                            :class="classeSomenteLeitura"
                            :aria-describedby="descricao"
                        />
                        <input
                            v-else
                            id="telefone"
                            :value="form.telefone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            maxlength="15"
                            required
                            placeholder="(00) 00000-0000"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'telefone', formatarTelefone)"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="telefone_adicional" rotulo="Telefone adicional" :erro="form.errors.telefone_adicional" v-slot="{ descricao, invalido }">
                        <input
                            id="telefone_adicional"
                            :value="form.telefone_adicional"
                            type="tel"
                            inputmode="numeric"
                            maxlength="15"
                            placeholder="(00) 00000-0000"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'telefone_adicional', formatarTelefone)"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="email" class="md:col-span-2" rotulo="E-mail" obrigatorio :govbr="!!govbr.email" :erro="form.errors.email" v-slot="{ descricao, invalido }">
                        <input
                            v-if="govbr.email"
                            id="email"
                            :value="form.email"
                            type="email"
                            readonly
                            :class="classeSomenteLeitura"
                            :aria-describedby="descricao"
                        />
                        <input
                            v-else
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            maxlength="255"
                            required
                            placeholder="Digite seu e-mail"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('email')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-identidade" :numero="5" titulo="Identidade e perfil">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="8" r="3.5" />
                        <path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="identidade_genero" rotulo="Identidade de gênero" obrigatorio :erro="form.errors.identidade_genero" v-slot="{ descricao, invalido }">
                        <select
                            id="identidade_genero"
                            v-model="form.identidade_genero"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('identidade_genero')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.identidadesGenero" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="raca_cor" rotulo="Raça/cor" obrigatorio :erro="form.errors.raca_cor" v-slot="{ descricao, invalido }">
                        <select
                            id="raca_cor"
                            v-model="form.raca_cor"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('raca_cor')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.racasCores" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="escolaridade" rotulo="Escolaridade" obrigatorio :erro="form.errors.escolaridade" v-slot="{ descricao, invalido }">
                        <select
                            id="escolaridade"
                            v-model="form.escolaridade"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('escolaridade')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.escolaridades" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="renda" rotulo="Renda mensal" obrigatorio :erro="form.errors.renda" v-slot="{ descricao, invalido }">
                        <select
                            id="renda"
                            v-model="form.renda"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('renda')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.faixasDeRenda" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="povo_tradicional" rotulo="Povo ou comunidade tradicional" obrigatorio :erro="form.errors.povo_tradicional" v-slot="{ descricao, invalido }">
                        <select
                            id="povo_tradicional"
                            v-model="form.povo_tradicional"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('povo_tradicional')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.povosTradicionais" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro id="anos_atuacao" rotulo="Anos de atuação na área cultural" obrigatorio :erro="form.errors.anos_atuacao" v-slot="{ descricao, invalido }">
                        <input
                            id="anos_atuacao"
                            v-model="form.anos_atuacao"
                            type="text"
                            inputmode="numeric"
                            maxlength="3"
                            required
                            placeholder="0"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('anos_atuacao')"
                        />
                    </CampoCadastro>

                    <div class="md:col-span-2">
                        <Interruptor id="pcd" v-model="form.pcd" rotulo="Sou uma pessoa com deficiência (PcD)" :erro="form.errors.pcd" />
                    </div>

                    <CampoCadastro v-if="form.pcd" id="deficiencia" rotulo="Tipo de deficiência" obrigatorio :erro="form.errors.deficiencia" v-slot="{ descricao, invalido }">
                        <select
                            id="deficiencia"
                            v-model="form.deficiencia"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('deficiencia')"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option v-for="opcao in opcoes.tiposDeficiencia" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro
                        v-if="form.pcd && form.deficiencia === 'outra'"
                        id="deficiencia_outra"
                        rotulo="Qual?"
                        obrigatorio
                        :erro="form.errors.deficiencia_outra"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="deficiencia_outra"
                            v-model="form.deficiencia_outra"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="Diga qual é a deficiência"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('deficiencia_outra')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-endereco" :numero="6" titulo="Endereço">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z" />
                        <circle cx="12" cy="9" r="2.5" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-6">
                    <CampoCadastro id="cep" class="md:col-span-2" rotulo="CEP" obrigatorio :erro="form.errors.cep" v-slot="{ descricao, invalido }">
                        <input
                            id="cep"
                            :value="form.cep"
                            type="text"
                            inputmode="numeric"
                            autocomplete="postal-code"
                            maxlength="9"
                            required
                            placeholder="00000-000"
                            :class="[classeCampo, 'tabular-nums']"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="mascarar($event, 'cep', formatarCep)"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="logradouro" class="md:col-span-4" rotulo="Logradouro" obrigatorio :erro="form.errors.logradouro" v-slot="{ descricao, invalido }">
                        <input
                            id="logradouro"
                            v-model="form.logradouro"
                            type="text"
                            autocomplete="address-line1"
                            maxlength="255"
                            required
                            placeholder="Rua, avenida etc."
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('logradouro')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="numero" class="md:col-span-2" rotulo="Número" :erro="form.errors.numero" v-slot="{ descricao, invalido }">
                        <input
                            id="numero"
                            v-model="form.numero"
                            type="text"
                            maxlength="20"
                            placeholder="123"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="bairro" class="md:col-span-2" rotulo="Bairro" obrigatorio :erro="form.errors.bairro" v-slot="{ descricao, invalido }">
                        <input
                            id="bairro"
                            v-model="form.bairro"
                            type="text"
                            maxlength="120"
                            required
                            placeholder="Nome do bairro"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('bairro')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="complemento" class="md:col-span-2" rotulo="Complemento" :erro="form.errors.complemento" v-slot="{ descricao, invalido }">
                        <input
                            id="complemento"
                            v-model="form.complemento"
                            type="text"
                            autocomplete="address-line2"
                            maxlength="120"
                            placeholder="Apto., bloco etc."
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="cidade" class="md:col-span-3" rotulo="Cidade" obrigatorio :erro="form.errors.cidade" v-slot="{ descricao, invalido }">
                        <input
                            id="cidade"
                            v-model="form.cidade"
                            type="text"
                            autocomplete="address-level2"
                            maxlength="120"
                            required
                            placeholder="Nome da cidade"
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('cidade')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="estado" class="md:col-span-3" rotulo="Estado" obrigatorio :erro="form.errors.estado" v-slot="{ descricao, invalido }">
                        <select
                            id="estado"
                            v-model="form.estado"
                            autocomplete="address-level1"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('estado')"
                        >
                            <option value="" disabled>Selecione o estado</option>
                            <option v-for="estado in opcoes.estados" :key="estado.valor" :value="estado.valor">{{ estado.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro
                        id="territorio"
                        class="md:col-span-6"
                        rotulo="Território"
                        obrigatorio
                        ajuda="A Prefeitura-Bairro de Salvador onde você mora."
                        :erro="form.errors.territorio"
                        v-slot="{ descricao, invalido }"
                    >
                        <select
                            id="territorio"
                            v-model="form.territorio"
                            required
                            :class="classeCampo"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @change="form.clearErrors('territorio')"
                        >
                            <option value="" disabled>Selecione um território</option>
                            <option v-for="opcao in opcoes.territorios" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </CampoCadastro>

                    <CampoCadastro
                        id="comprovante_residencia"
                        class="md:col-span-6"
                        rotulo="Comprovante de residência"
                        :erro="form.errors.comprovante_residencia"
                        v-slot="{ descricao, invalido }"
                    >
                        <CampoArquivo
                            id="comprovante_residencia"
                            :accept="tiposDocumento"
                            :dica="dicaDocumento"
                            :arquivo="form.comprovante_residencia"
                            :enviado="arquivoEnviado('comprovante_residencia')"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherArquivo($event, 'comprovante_residencia')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <div class="flex flex-col-reverse gap-4 sm:flex-row sm:items-start sm:justify-between">
                <Link
                    href="/"
                    class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill border border-tinta-suave/70 bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-home-tinta uppercase no-underline hover:border-acao hover:text-acao"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    Cancelar
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
