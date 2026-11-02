<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../Components/Retaguarda/PaginaRetaguarda.vue';
import { classeCaixa, classeMarcacao, classeTexto } from '../../Components/Retaguarda/listaEditavel';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { ArquivoCadastro, RetaguardaAnexosDoCadastroProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAnexosDoCadastroProps>();

// Caminhos das rotas retaguarda.anexos-cadastro.update, retaguarda.anexos-cadastro.instrucoes-ia e
// retaguarda.integracao-ia.edit (o front não tem helper de rotas nomeadas).
const URL_ANEXOS = '/retaguarda/anexos-cadastro';
const URL_INSTRUCOES = '/retaguarda/anexos-cadastro/instrucoes-ia';
const URL_INTEGRACAO_IA = '/retaguarda/integracao-ia';

type ParametrosDoAnexo = { validar_ia: boolean; instrucao_ia: string };

// Pela chave do campo, como o SalvarAnexosDoCadastroRequest espera: anexos.{campo}.validar_ia e .instrucao_ia.
const form = useForm({
    anexos: Object.fromEntries(props.anexos.map((anexo) => [anexo.campo, { validar_ia: anexo.validar_ia, instrucao_ia: anexo.instrucao_ia }])) as Record<
        ArquivoCadastro,
        ParametrosDoAnexo
    >,
});

const erros = computed<Partial<Record<string, string>>>(() => form.errors as Partial<Record<string, string>>);
const erro = (campo: ArquivoCadastro, nome: keyof ParametrosDoAnexo): string | undefined => erros.value[`anexos.${campo}.${nome}`];
const limpar = (campo: ArquivoCadastro, ...nomes: (keyof ParametrosDoAnexo)[]): void => {
    form.clearErrors(...(nomes.map((nome) => `anexos.${campo}.${nome}`) as Parameters<typeof form.clearErrors>));
};

/** Dispensada, a instrução deixa de ser obrigatória: o erro de texto em branco não vale mais. */
const escolherValidacao = (campo: ArquivoCadastro): void => {
    limpar(campo, 'validar_ia');
    if (!form.anexos[campo].validar_ia) limpar(campo, 'instrucao_ia');
};

const salvar = (): void => {
    form.put(URL_ANEXOS, {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-anexos-cadastro'),
    });
};

// Instruções geradas pela IA ("Gerar com IA" no anexo, "Gerar instruções de todos" no topo). Só preenchem o
// formulário: nada é gravado até a equipe salvar.

/** Aviso e carregamento de "Gerar instruções de todos"; os dos anexos usam o campo. */
const TODOS = 'todos';
const SESSAO_EXPIRADA = 'Sua sessão expirou. Recarregue a página e entre de novo.';

type RespostaInstrucoes = { ok: true; instrucoes: Partial<Record<ArquivoCadastro, string>> } | { ok: false; mensagem: string };

/** Uma geração por vez: TODOS ou o campo do anexo; null = nenhuma. */
const gerando = ref<ArquivoCadastro | typeof TODOS | null>(null);
const avisos = reactive<Partial<Record<string, { mensagem: string; erro: boolean }>>>({});

const avisar = (chave: string, mensagem: string, comErro: boolean): void => {
    avisos[chave] = { mensagem, erro: comErro };
};

const tokenXsrf = (): string => {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

const mensagemDeFalha = async (resposta: Response): Promise<string> => {
    if (resposta.status === 422) {
        // Falha da IA vem em "erro"; campo recusado na validação, em "errors".
        const corpo = (await resposta.json().catch(() => ({}))) as { erro?: string };
        return corpo.erro ?? 'Não foi possível gerar as instruções agora. Tente de novo.';
    }
    if (resposta.status === 429) return 'Muitas gerações seguidas. Aguarde um minuto e tente de novo.';
    if (resposta.status === 401 || resposta.status === 419) return SESSAO_EXPIRADA;
    return 'Não foi possível gerar as instruções agora. Tente de novo.';
};

/** Uma instrução por campo pedido. Sempre resolve: qualquer falha vira { ok: false, mensagem }. */
const pedirInstrucoes = async (campos: ArquivoCadastro[]): Promise<RespostaInstrucoes> => {
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
            body: JSON.stringify({ campos }),
        });
        if (resposta.type === 'opaqueredirect') return { ok: false, mensagem: SESSAO_EXPIRADA };
        if (!resposta.ok) return { ok: false, mensagem: await mensagemDeFalha(resposta) };
        const { instrucoes } = (await resposta.json()) as { instrucoes: Partial<Record<ArquivoCadastro, string>> };
        return { ok: true, instrucoes };
    } catch {
        return { ok: false, mensagem: 'Sem conexão com o servidor. Verifique a internet e tente de novo.' };
    }
};

const preencher = (campo: ArquivoCadastro, instrucao: string): void => {
    form.anexos[campo].instrucao_ia = instrucao;
    limpar(campo, 'instrucao_ia');
};

/** "Gerar com IA" do anexo: sobrescreve o que estiver escrito nele. */
const gerarDoAnexo = async (campo: ArquivoCadastro): Promise<void> => {
    gerando.value = campo;
    avisar(campo, '', false);
    const resposta = await pedirInstrucoes([campo]);
    gerando.value = null;

    if (!resposta.ok) {
        avisar(campo, resposta.mensagem, true);
        return;
    }
    preencher(campo, resposta.instrucoes[campo] ?? '');
    avisar(campo, 'Instrução gerada. Revise e salve para gravá-la.', false);
};

/** "Gerar instruções de todos": só os anexos sem texto, validados ou não; o que a equipe escreveu fica. */
const gerarTodos = async (): Promise<void> => {
    const semTexto = props.anexos.map((anexo) => anexo.campo).filter((campo) => form.anexos[campo].instrucao_ia.trim() === '');
    if (semTexto.length === 0) {
        avisar(TODOS, 'Todos os documentos já têm instrução.', false);
        return;
    }

    gerando.value = TODOS;
    avisar(TODOS, '', false);
    const resposta = await pedirInstrucoes(semTexto);
    gerando.value = null;

    if (!resposta.ok) {
        avisar(TODOS, resposta.mensagem, true);
        return;
    }

    let geradas = 0;
    for (const campo of semTexto) {
        const instrucao = resposta.instrucoes[campo];
        // Escrita pela equipe enquanto a IA respondia: fica a da equipe.
        if (instrucao !== undefined && form.anexos[campo].instrucao_ia.trim() === '') {
            preencher(campo, instrucao);
            geradas++;
        }
    }
    avisar(TODOS, geradas === 1 ? '1 instrução gerada. Revise e salve para gravá-la.' : `${geradas} instruções geradas. Revise e salve para gravá-las.`, false);
};

const classeBotaoIa =
    'pressionavel inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-wait disabled:opacity-70';
const classeEtiqueta = 'inline-flex rounded-pill bg-nevoa px-2.5 py-0.5 text-[0.75rem] font-bold tracking-[0.06em] text-acao uppercase';
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        titulo="Anexos do cadastro"
        descricao="Anexos que o proponente envia na etapa Anexos do cadastro, no portal. Para cada um, escolha se a IA confere o arquivo e escreva o que ela deve verificar. Vale para os próximos envios: os arquivos já conferidos não são conferidos de novo."
    >
        <Head title="Anexos do cadastro · Retaguarda" />

        <template #detalhe>
            <span class="selo-faixa">
                <span aria-hidden="true" class="size-2 rounded-full" :class="validacaoLigada ? 'bg-situacao-aberto' : 'bg-situacao-encerrado'" />
                {{ validacaoLigada ? 'Validação por IA ligada' : 'Validação por IA desligada' }}
            </span>
        </template>

        <form id="formulario-anexos-cadastro" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <div v-if="!validacaoLigada" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3 2 20h20zM12 10v4M12 17h.01" />
                </svg>
                <p class="text-[0.9375rem] leading-[1.6] font-semibold">
                    A validação por IA está desligada em Integração com IA. As escolhas desta tela ficam salvas, mas nenhum documento é conferido até ela ser ligada.
                    <Link :href="URL_INTEGRACAO_IA" class="underline">Abrir Integração com IA</Link>
                </p>
            </div>

            <div class="painel-retaguarda flex flex-col gap-2 p-5 md:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                    <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">
                        A IA escreve o que conferir em cada documento a partir do nome dele e do que o portal pede ao proponente. Só os documentos sem texto são preenchidos; nada é gravado até
                        você salvar.
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

            <SecaoCadastro v-for="(anexo, posicao) in anexos" :id="`secao-${anexo.campo}`" :key="anexo.campo" :numero="posicao + 1" :titulo="anexo.rotulo">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 3h7l5 5v13H7zM14 3v5h5M10 15l2 2 4-4" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios v-if="posicao === 0" class="mb-5" />

                <div class="grid gap-y-5">
                    <div class="flex flex-col items-start gap-2">
                        <span :class="classeEtiqueta">{{ anexo.obrigatorio ? 'Obrigatório no cadastro' : 'Opcional no cadastro' }}</span>
                        <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">{{ anexo.descricao }}</p>
                    </div>

                    <fieldset class="min-w-0" :aria-describedby="erro(anexo.campo, 'validar_ia') ? `${anexo.campo}-validacao-erro` : undefined">
                        <legend class="text-[0.9375rem] font-semibold">Validação<span aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span></legend>
                        <div class="mt-1.5 flex flex-col">
                            <label :class="classeMarcacao">
                                <input
                                    v-model="form.anexos[anexo.campo].validar_ia"
                                    type="radio"
                                    :name="`validacao-${anexo.campo}`"
                                    :value="true"
                                    required
                                    :class="classeCaixa"
                                    :aria-invalid="erro(anexo.campo, 'validar_ia') ? true : undefined"
                                    :aria-describedby="`${anexo.campo}-validar-ajuda`"
                                    @change="escolherValidacao(anexo.campo)"
                                />
                                <span>
                                    <span class="font-semibold">Validar com IA</span>
                                    <span :id="`${anexo.campo}-validar-ajuda`" class="block text-[0.8125rem] text-tinta-suave">
                                        A IA confere o arquivo assim que ele é anexado. Reprovado, o proponente precisa trocar o arquivo para seguir no cadastro.
                                    </span>
                                </span>
                            </label>
                            <label :class="classeMarcacao">
                                <input
                                    v-model="form.anexos[anexo.campo].validar_ia"
                                    type="radio"
                                    :name="`validacao-${anexo.campo}`"
                                    :value="false"
                                    required
                                    :class="classeCaixa"
                                    :aria-invalid="erro(anexo.campo, 'validar_ia') ? true : undefined"
                                    :aria-describedby="`${anexo.campo}-dispensar-ajuda`"
                                    @change="escolherValidacao(anexo.campo)"
                                />
                                <span>
                                    <span class="font-semibold">Dispensar validação</span>
                                    <span :id="`${anexo.campo}-dispensar-ajuda`" class="block text-[0.8125rem] text-tinta-suave">O arquivo é aceito como foi enviado, sem passar pela IA.</span>
                                </span>
                            </label>
                        </div>
                        <p v-if="erro(anexo.campo, 'validar_ia')" :id="`${anexo.campo}-validacao-erro`" class="text-[0.875rem] font-semibold text-invalido-texto">
                            {{ erro(anexo.campo, 'validar_ia') }}
                        </p>
                    </fieldset>

                    <div class="flex min-w-0 flex-col gap-3">
                        <CampoCadastro
                            :id="`${anexo.campo}-instrucao`"
                            rotulo="O que a IA deve conferir"
                            :obrigatorio="form.anexos[anexo.campo].validar_ia"
                            ajuda="Não aparece para o proponente. Com a validação dispensada, o texto fica guardado para quando ela voltar."
                            :erro="erro(anexo.campo, 'instrucao_ia')"
                            v-slot="{ descricao, invalido }"
                        >
                            <textarea
                                :id="`${anexo.campo}-instrucao`"
                                v-model="form.anexos[anexo.campo].instrucao_ia"
                                rows="4"
                                maxlength="2000"
                                :required="form.anexos[anexo.campo].validar_ia"
                                placeholder="Ex.: Confira se o arquivo é mesmo este documento, se dá para ler e se os dados conferem com os do cadastro."
                                :class="classeTexto"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="limpar(anexo.campo, 'instrucao_ia')"
                            />
                        </CampoCadastro>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <button type="button" :disabled="gerando !== null" :class="classeBotaoIa" @click="gerarDoAnexo(anexo.campo)">
                                <svg
                                    v-if="gerando === anexo.campo"
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
                                {{ gerando === anexo.campo ? 'Gerando…' : 'Gerar com IA' }}<span class="sr-only">{{ ` o que conferir em ${anexo.rotulo}` }}</span>
                            </button>
                            <p
                                aria-live="polite"
                                class="min-w-0 flex-1 basis-60 text-[0.9375rem]"
                                :class="avisos[anexo.campo]?.erro ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'"
                            >
                                {{ avisos[anexo.campo]?.mensagem }}
                            </p>
                        </div>
                    </div>
                </div>
            </SecaoCadastro>

            <BarraAcoes :salvar="form.processing ? 'Salvando…' : 'Salvar'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
