<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import CampoArquivo from '../../Components/CampoArquivo.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import { caracteres } from '../../Components/Inscricao/questionario';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { PortalRecursoDaInscricaoProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<PortalRecursoDaInscricaoProps>();

// Caminho das rotas portal.inscricoes.show e portal.inscricoes.recurso.store (o front não tem helper de rotas nomeadas).
const URL_DA_INSCRICAO = `/portal/inscricoes/${props.inscricao.id}`;
const URL_DO_RECURSO = `${URL_DA_INSCRICAO}/recurso`;

// Mesmos limites e mensagens do servidor (EnviarRecursoRequest, Inscricao::TIPOS_DE_ARQUIVO,
// InscricaoDocumentoController::ERRO_ARQUIVO), e mesmos tipos e checagem do DocumentoExigido.
const LIMITE_DA_JUSTIFICATIVA = 4000;
const ERRO_VAZIO = 'Escreva a justificativa ou envie ao menos um documento.';
const TAMANHO_MAXIMO_MB = 10;
const ERRO_ARQUIVO = `Envie um PDF ou uma imagem (JPG ou PNG) de até ${TAMANHO_MAXIMO_MB} MB.`;
const TIPOS_ACEITOS = ['application/pdf', 'image/jpeg', 'image/png'];
const EXTENSOES_ACEITAS = ['.pdf', '.jpg', '.jpeg', '.png'];
const ACEITOS = [...TIPOS_ACEITOS, ...EXTENSOES_ACEITAS].join(',');

/** Os arquivos novos, pelo id do documento do edital: o FormData sai como arquivos[<id>]. */
const formulario = useForm<{ justificativa: string; arquivos: Record<number, File> }>({
    justificativa: '',
    arquivos: {},
});

// Erros por caminho ("arquivos.12"), que o tipo do useForm não descreve.
const erros = computed<Record<string, string | undefined>>(() => formulario.errors as Record<string, string | undefined>);

const usados = computed<number>(() => caracteres(formulario.justificativa));
const passouDoLimite = computed<boolean>(() => usados.value > LIMITE_DA_JUSTIFICATIVA);
const numero = (valor: number): string => valor.toLocaleString('pt-BR');

const idDoArquivo = (documentoId: number): string => `recurso-arquivo-${documentoId}`;

const limparErro = (campo: string): void => {
    formulario.clearErrors(campo as keyof typeof formulario.errors);
};

const alterarJustificativa = (texto: string): void => {
    formulario.justificativa = texto;
    limparErro('justificativa');
};

// O arquivo que não é PDF nem imagem JPG ou PNG, ou passa de 10 MB, nem entra no formulário; o servidor confere de novo.
const escolher = (documentoId: number, arquivo: File | null): void => {
    const outros = { ...formulario.arquivos };
    delete outros[documentoId];
    formulario.arquivos = outros;
    limparErro(`arquivos.${documentoId}`);
    limparErro('arquivos');
    if (arquivo === null) {
        return;
    }

    const nome = arquivo.name.toLowerCase();
    const aceito = TIPOS_ACEITOS.includes(arquivo.type) || EXTENSOES_ACEITAS.some((extensao) => nome.endsWith(extensao));
    if (!aceito || arquivo.size > TAMANHO_MAXIMO_MB * 1024 * 1024) {
        formulario.setError({ [`arquivos.${documentoId}`]: ERRO_ARQUIVO } as typeof formulario.errors);
        const entrada = document.getElementById(idDoArquivo(documentoId)) as HTMLInputElement | null;
        if (entrada) {
            entrada.value = '';
        }
        void nextTick(() => entrada?.focus());
        return;
    }

    formulario.arquivos = { ...formulario.arquivos, [documentoId]: arquivo };
    // Com um arquivo, o recurso já não está vazio; outro erro da justificativa (o limite) continua.
    if (erros.value.justificativa === ERRO_VAZIO) {
        limparErro('justificativa');
    }
};

const temArquivo = computed<boolean>(() => Object.keys(formulario.arquivos).length > 0);

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const dialogo = ref<HTMLDialogElement | null>(null);
const fecharDialogo = (): void => dialogo.value?.close();

// O erro geral dos arquivos (documento que não aceita troca) não tem campo: o foco vai para a mensagem.
const focarErros = (): void => {
    if (erros.value.arquivos) {
        void nextTick(() => document.getElementById('recurso-erro-arquivos')?.focus());
        return;
    }
    focarPrimeiroErro('#formulario-recurso');
};

// Mesma regra do servidor: justificativa só com espaços conta como vazia.
const confirmar = (): void => {
    if (formulario.justificativa.trim() === '' && !temArquivo.value) {
        formulario.setError('justificativa', ERRO_VAZIO);
        focarErros();
        return;
    }
    if (passouDoLimite.value) {
        focarErros();
        return;
    }
    dialogo.value?.showModal();
};

const enviar = (): void => {
    formulario.post(URL_DO_RECURSO, {
        forceFormData: true,
        // Fechar antes de focar: ao fechar, o diálogo devolve o foco ao botão que abriu.
        onError: () => {
            fecharDialogo();
            focarErros();
        },
        onFinish: fecharDialogo,
    });
};

const classeCartao = 'rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-10';
const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
const classeBotao =
    'pressionavel inline-flex min-h-12 items-center justify-center rounded-pill px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] uppercase no-underline disabled:cursor-not-allowed disabled:opacity-70';
const classePrimario = `${classeBotao} bg-acao text-sobre-acao hover:bg-acao-escuro`;
const classeSecundario = `${classeBotao} border border-tinta-suave/70 bg-superficie text-home-tinta hover:border-acao hover:text-acao`;
</script>

<template>
    <div>
        <Head :title="`Recurso — ${edital.titulo}`" />

        <FaixaPortal aria-labelledby="recurso-titulo">
            <div class="flex flex-col gap-3">
                <Link
                    :href="URL_DA_INSCRICAO"
                    class="inline-flex min-h-11 items-center gap-2 self-start text-[0.8125rem] font-semibold tracking-[0.12em] text-white/85 uppercase no-underline hover:text-white"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 6l-6 6 6 6" />
                    </svg>
                    Voltar à inscrição
                </Link>
                <h1 id="recurso-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Recurso
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    <strong class="font-semibold text-white">{{ edital.titulo }}</strong
                    ><template v-if="inscricao.numero"> · nº {{ inscricao.numero }}</template><br />
                    Prazo para recurso: até {{ prazo }}, às 23:59.
                </p>
            </div>
        </FaixaPortal>

        <div class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <section aria-labelledby="recurso-motivo-titulo" :class="classeCartao">
                <h2 id="recurso-motivo-titulo" class="text-[1.25rem] font-bold">Por que a inscrição não foi homologada</h2>
                <dl class="mt-4 flex flex-col gap-4">
                    <div v-if="motivo">
                        <dt :class="classeTermo">Motivo</dt>
                        <dd class="break-words whitespace-pre-line">{{ motivo }}</dd>
                    </div>
                    <div v-if="itens.length > 0">
                        <dt :class="classeTermo">O que foi apontado</dt>
                        <dd>
                            <ul class="mt-1 list-disc space-y-1 pl-6">
                                <li v-for="(item, indice) in itens" :key="indice" class="break-words">
                                    {{ item.nome }}<template v-if="item.observacao"> — {{ item.observacao }}</template>
                                </li>
                            </ul>
                        </dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="recurso-envio-titulo" :class="classeCartao">
                <h2 id="recurso-envio-titulo" class="text-[1.25rem] font-bold">Seu recurso</h2>
                <p class="mt-2 max-w-2xl leading-[1.6] text-tinta-suave">
                    Explique por que a inscrição deve ser homologada, envie arquivos novos para os documentos apontados, ou faça os dois. O recurso é um só: confira tudo antes de
                    enviar.
                </p>

                <form id="formulario-recurso" novalidate class="mt-6 flex flex-col gap-6" @submit.prevent="confirmar">
                    <CampoCadastro id="recurso-justificativa" rotulo="Justificativa" :erro="erros.justificativa" v-slot="{ descricao, invalido }">
                        <textarea
                            id="recurso-justificativa"
                            rows="8"
                            :value="formulario.justificativa"
                            class="w-full rounded-[12px] border border-tinta-suave/70 bg-superficie px-4 py-3 text-base leading-[1.6] text-home-tinta placeholder:text-tinta-suave"
                            :aria-invalid="invalido || passouDoLimite || undefined"
                            :aria-describedby="[descricao, 'recurso-justificativa-contador'].filter(Boolean).join(' ')"
                            @input="alterarJustificativa(($event.target as HTMLTextAreaElement).value)"
                        ></textarea>
                        <p
                            id="recurso-justificativa-contador"
                            class="text-[0.8125rem] tabular-nums"
                            :class="passouDoLimite ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'"
                        >
                            {{ numero(usados) }} de {{ numero(LIMITE_DA_JUSTIFICATIVA) }} caracteres<template v-if="passouDoLimite"> (passou do limite)</template>
                        </p>
                    </CampoCadastro>

                    <section v-if="documentos.length > 0" aria-labelledby="recurso-documentos-titulo" class="flex flex-col gap-4 border-t border-hairline pt-6">
                        <h3 id="recurso-documentos-titulo" class="text-[1.0625rem] font-bold">Documentos apontados</h3>
                        <p class="text-[0.875rem] leading-[1.5] text-tinta-suave">
                            O arquivo novo não apaga o que você já enviou: se o recurso for aceito, a equipe troca pelo novo.
                        </p>
                        <p
                            v-if="erros.arquivos"
                            id="recurso-erro-arquivos"
                            role="alert"
                            tabindex="-1"
                            class="rounded-[12px] border border-invalido-texto/40 bg-invalido-fundo px-4 py-3 font-semibold text-invalido-texto"
                        >
                            {{ erros.arquivos }}
                        </p>
                        <ul class="flex flex-col gap-4">
                            <li v-for="documento in documentos" :key="documento.id">
                                <article :aria-labelledby="`${idDoArquivo(documento.id)}-nome`" class="flex flex-col gap-3 rounded-[16px] border border-hairline p-4 md:p-5">
                                    <h4 :id="`${idDoArquivo(documento.id)}-nome`" class="font-semibold break-words">{{ documento.nome }}</h4>
                                    <p v-if="documento.observacao" class="text-[0.9375rem] leading-[1.5]">
                                        <span class="font-semibold">Apontado:</span> {{ documento.observacao }}
                                    </p>
                                    <p v-if="documento.orientacao" class="text-[0.875rem] leading-[1.5] text-tinta-suave">{{ documento.orientacao }}</p>
                                    <CampoCadastro
                                        :id="idDoArquivo(documento.id)"
                                        :rotulo="`Novo arquivo de ${documento.nome}`"
                                        :erro="erros[`arquivos.${documento.id}`]"
                                        v-slot="{ descricao, invalido }"
                                    >
                                        <CampoArquivo
                                            :id="idDoArquivo(documento.id)"
                                            :accept="ACEITOS"
                                            :dica="`PDF ou imagem (JPG ou PNG), até ${TAMANHO_MAXIMO_MB} MB`"
                                            :arquivo="formulario.arquivos[documento.id] ?? null"
                                            :descricao="descricao"
                                            :invalido="invalido"
                                            :desabilitado="formulario.processing"
                                            @escolher="(arquivo) => escolher(documento.id, arquivo)"
                                        />
                                    </CampoCadastro>
                                </article>
                            </li>
                        </ul>
                    </section>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-6">
                        <Link :href="URL_DA_INSCRICAO" :class="classeSecundario">Cancelar</Link>
                        <button type="submit" :disabled="formulario.processing" :class="[classePrimario, 'ml-auto']">Enviar recurso</button>
                    </div>
                </form>
            </section>
        </div>

        <dialog
            ref="dialogo"
            aria-labelledby="recurso-confirmar-titulo"
            aria-describedby="recurso-confirmar-aviso"
            class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <h2 id="recurso-confirmar-titulo" class="text-[1.25rem] leading-tight font-bold">Enviar o recurso?</h2>
            <p id="recurso-confirmar-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">Depois do envio, nada mais pode ser alterado.</p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    autofocus
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                    @click="fecharDialogo"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="formulario.processing"
                    class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    @click="enviar"
                >
                    {{ formulario.processing ? 'Enviando…' : 'Enviar recurso' }}
                </button>
            </div>
        </dialog>
    </div>
</template>
