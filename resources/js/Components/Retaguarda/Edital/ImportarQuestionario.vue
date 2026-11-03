<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../CampoArquivo.vue';
import CampoCadastro from '../../CampoCadastro.vue';
import Interruptor from '../../Interruptor.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';
import ProgressoDaAnalise from './ProgressoDaAnalise.vue';
import type { ImportacaoDoQuestionario } from '../../../types';

/**
 * "Importar com IA" da aba Questionário: envia o espelho do formulário (PDF ou Word) e acompanha a análise em segundo
 * plano. Concluída, o questionário do edital foi substituído no servidor; a tela recarrega para mostrá-lo.
 */
const props = defineProps<{
    /** Endereço do edital salvo; null na criação. */
    editalSlug: string | null;
    temCategoriaSalva: boolean;
    /** O edital já tem seções no questionário: a importação as substitui e pede a confirmação. */
    temSecoes: boolean;
    travado: boolean;
    avisoTravado: string;
    /** O formulário tem alterações não salvas: a importação troca o questionário gravado e a tela recarrega. */
    alteracoesNaoSalvas: boolean;
    /** Há provedor principal com modelo e chave. */
    configurada: boolean;
    tamanhoMaximoMb: number;
    importacao: ImportacaoDoQuestionario | null;
}>();

const MOTIVO_SEM_SALVAR = 'Salve o questionário antes de importar.';
// Mesmos textos das recusas do servidor (ImportacaoQuestionarioController).
const MOTIVO_SEM_CATEGORIA = 'Cadastre ao menos uma categoria e salve o edital antes de importar o questionário.';
const MOTIVO_EM_ANALISE = 'Já há uma importação do questionário em análise para este edital.';
const ERRO_CONFIRMAR = 'Confirme que o questionário atual será substituído.';
const SEM_CONFIGURACAO = 'Serviço de IA indisponível no momento. Caso persista, contate o administrador.';
const URL_CONSULTA = '/retaguarda/editais/questionario/importacoes';
const INTERVALO_CONSULTA_MS = 5_000;
// Na conclusão, a barra chega a 100% (a transição da largura leva 1 s) antes de a tela recarregar com o questionário
// importado: o mesmo tempo da lista de editais (BARRA_COMPLETA_MS em Editais/Index).
const ESPERA_DA_BARRA_MS = 1_200;
const FORMULARIO = '#formulario-importar-questionario';
const TIPOS_ACEITOS = '.pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

const dialogo = ref<HTMLDialogElement | null>(null);
const form = useForm({ arquivo: null as File | null, substituir: false });

// Cópia local: a consulta periódica atualiza a situação sem recarregar a página.
const atual = ref<ImportacaoDoQuestionario | null>(props.importacao ? { ...props.importacao } : null);
watch(
    () => props.importacao,
    (nova) => {
        atual.value = nova ? { ...nova } : null;
    },
);

const analisando = computed<boolean>(() => atual.value?.situacao === 'analisando');
// Só a conclusão vista nesta tela oferece "Ver o questionário importado"; ao abrir a página, a concluída já está carregada.
const concluidaAgora = ref<boolean>(false);
const consultaInterrompida = ref<boolean>(false);
const areaDeSituacao = ref<HTMLElement | null>(null);

const emit = defineEmits<{ concluida: [] }>();

const motivoDoBloqueio = computed<string | null>(() => {
    if (props.editalSlug === null) return MOTIVO_SEM_SALVAR;
    if (!props.temCategoriaSalva) return MOTIVO_SEM_CATEGORIA;
    if (props.travado) return props.avisoTravado;
    if (props.alteracoesNaoSalvas) return MOTIVO_SEM_SALVAR;
    if (analisando.value) return MOTIVO_EM_ANALISE;

    return null;
});

// Recusa do servidor quando a configuração saiu com a página aberta.
const recusa = ref<string>('');
const semConfiguracao = computed<boolean>(() => !props.configurada || recusa.value !== '');

// <dialog> nativo com showModal(): prende o foco, fecha com Esc e devolve o foco ao botão que abriu.
const abrir = (): void => dialogo.value?.showModal();
const fechar = (): void => dialogo.value?.close();

// Ao fechar (Cancelar, Esc ou depois de enviar), a escolha some, inclusive a recusada que ficou no campo nativo.
const aoFechar = (): void => {
    recusa.value = '';
    form.reset();
    form.clearErrors();
    const entrada = dialogo.value?.querySelector<HTMLInputElement>('input[type="file"]');
    if (entrada) entrada.value = '';
};

// O arquivo grande nem sai do navegador; o servidor confere de novo.
const escolher = (arquivo: File | null): void => {
    form.clearErrors('arquivo');

    if (arquivo && arquivo.size > props.tamanhoMaximoMb * 1024 * 1024) {
        form.arquivo = null;
        form.setError('arquivo', `O arquivo deve ter no máximo ${props.tamanhoMaximoMb} MB.`);
        return;
    }
    form.arquivo = arquivo;
};

const enviar = (): void => {
    if (props.editalSlug === null) return;
    form.clearErrors();

    if (!form.arquivo) form.setError('arquivo', 'Este campo é obrigatório.');
    if (props.temSecoes && !form.substituir) form.setError('substituir', ERRO_CONFIRMAR);
    if (form.hasErrors) {
        focarPrimeiroErro(FORMULARIO);
        return;
    }

    form.post(`/retaguarda/editais/${props.editalSlug}/questionario/importacoes`, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            fechar();
            void nextTick(() => areaDeSituacao.value?.focus());
        },
        onError: (erros) => {
            recusa.value = erros.configuracao ?? '';
            focarPrimeiroErro(FORMULARIO);
        },
    });
};

const textoBotao = computed(() => {
    if (!form.processing) return 'Importar';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Enviando…';
});

type SituacaoConsultada = Pick<ImportacaoDoQuestionario, 'situacao' | 'erro'>;

// Uma falha de rede fica para a próxima rodada.
const consultar = async (): Promise<void> => {
    const importacao = atual.value;
    if (importacao === null || importacao.situacao !== 'analisando') return;

    try {
        const resposta = await fetch(`${URL_CONSULTA}/${importacao.id}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        // Erro do cliente (sessão expirada, importação inexistente): repetir não adianta.
        if (resposta.status >= 400 && resposta.status < 500) {
            consultaInterrompida.value = true;
            pararConsulta();
            return;
        }
        if (!resposta.ok) return;

        const situacao = (await resposta.json()) as SituacaoConsultada;
        if (situacao.situacao === 'analisando' || atual.value?.id !== importacao.id) return;

        atual.value = { ...importacao, situacao: situacao.situacao, erro: situacao.erro };
        concluidaAgora.value = situacao.situacao === 'concluida';
        if (concluidaAgora.value) {
            emit('concluida');
            // Sem nada pendente no formulário, já mostra o questionário importado; com alterações, espera a pessoa decidir.
            // A espera é da barra: quem editou algo nesse meio-tempo também não perde a alteração.
            if (!props.alteracoesNaoSalvas) {
                recarga = setTimeout(() => {
                    recarga = undefined;
                    if (!props.alteracoesNaoSalvas) recarregarNoQuestionario();
                }, ESPERA_DA_BARRA_MS);
            }
        }
    } catch {
        // Sem conexão: tenta de novo na próxima rodada.
    }
};

let temporizador: ReturnType<typeof setInterval> | undefined;
// Recarga depois da barra completa; cancelada se a pessoa recarregar antes ("Ver o questionário importado") ou sair.
let recarga: ReturnType<typeof setTimeout> | undefined;
const cancelarRecarga = (): void => {
    clearTimeout(recarga);
    recarga = undefined;
};
const pararConsulta = (): void => {
    clearInterval(temporizador);
    temporizador = undefined;
};
watch(
    analisando,
    (emAnalise) => {
        if (emAnalise && temporizador === undefined) {
            temporizador = setInterval(() => void consultar(), INTERVALO_CONSULTA_MS);
        } else if (!emAnalise) {
            pararConsulta();
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    pararConsulta();
    cancelarRecarga();
});

// Recarrega a página já na aba Questionário (?aba=), com o formulário refeito a partir do que o servidor gravou.
const recarregarNoQuestionario = (): void => {
    cancelarRecarga();
    router.visit(window.location.pathname, { preserveState: false, preserveScroll: true });
};

const verQuestionario = (): void => {
    if (props.alteracoesNaoSalvas && !window.confirm('Há alterações não salvas neste formulário. Ao recarregar, elas serão perdidas. Continuar?')) return;

    recarregarNoQuestionario();
};
</script>

<template>
    <!-- Sem caixa própria: o botão e a situação entram na grade do cabeçalho do QuestionarioEdital (o botão ao lado do
         "Vincular em lote", a situação embaixo dos dois). -->
    <div class="contents">
        <button
            type="button"
            :disabled="motivoDoBloqueio !== null"
            :aria-describedby="motivoDoBloqueio === null ? undefined : analisando ? 'importar-questionario-situacao' : 'importar-questionario-motivo'"
            class="pressionavel inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50 @lg:col-start-1 @lg:row-start-2 @4xl:col-start-2 @4xl:row-start-1"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.94 15.5a2 2 0 0 0-1.44-1.44l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0l1.58 6.13a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0zM20 3v4M22 5h-4M4 17v2M5 18H3" />
            </svg>
            Importar com IA
        </button>

        <!-- Em análise, o motivo do botão travado é o próprio cartão da análise (aria-describedby aponta para ele). -->
        <p
            v-if="motivoDoBloqueio !== null && !analisando"
            id="importar-questionario-motivo"
            class="order-1 text-[0.8125rem] leading-[1.5] text-tinta-suave @lg:col-span-2 @lg:w-0 @lg:min-w-full @4xl:col-start-2"
        >
            {{ motivoDoBloqueio }}
        </p>

        <!-- Vazia, não ocupa a linha da grade; fica no DOM para o leitor de tela anunciar a situação que entrar. Com
             w-0 e min-w-full, o texto longo quebra na largura dos dois botões em vez de alargar as colunas deles. -->
        <div
            id="importar-questionario-situacao"
            ref="areaDeSituacao"
            tabindex="-1"
            role="status"
            class="order-1 flex flex-col items-stretch text-[0.8125rem] leading-[1.5] outline-offset-4 empty:hidden @lg:col-span-2 @lg:w-0 @lg:min-w-full @4xl:col-start-2"
        >
            <template v-if="atual">
                <!-- Análise em curso (ou que acabou de terminar): um cartão com a barra estimada, a mesma da importação do
                     edital (a IA não informa o andamento), e o arquivo. A barra continua na conclusão e se completa. -->
                <div v-if="analisando || concluidaAgora" class="flex flex-col gap-3 rounded-[16px] border border-hairline bg-nevoa/60 p-4">
                    <ProgressoDaAnalise
                        larga
                        :pdf-nome="atual.arquivoNome"
                        :segundos-decorridos="atual.segundosDecorridos ?? 0"
                        :segundos-previstos="atual.segundosPrevistos ?? 120"
                        :concluida="!analisando"
                        titulo="Analisando o questionário…"
                        texto-concluida="Questionário importado."
                    />

                    <div class="flex min-w-0 items-start gap-2 border-t border-hairline pt-3 text-tinta-suave">
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                            <path d="M14 3v5h5" />
                        </svg>
                        <p class="min-w-0">
                            <span class="block truncate font-semibold text-home-tinta" :title="atual.arquivoNome">{{ atual.arquivoNome }}</span>
                            <span>Enviado em {{ atual.enviadaEm }}.</span>
                            <span v-if="analisando"> Você pode continuar usando o sistema.</span>
                        </p>
                    </div>

                    <p v-if="analisando && consultaInterrompida" class="font-semibold text-aviso-texto">Não foi possível acompanhar a análise. Recarregue a página para ver a situação.</p>

                    <div v-if="concluidaAgora" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p v-if="alteracoesNaoSalvas" class="font-semibold text-texto-verde">Como há alterações não salvas, ele aparece quando você recarregar.</p>
                        <button
                            type="button"
                            class="pressionavel -ml-3 inline-flex min-h-11 items-center rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-superficie"
                            @click="verQuestionario"
                        >
                            Ver o questionário importado
                        </button>
                    </div>
                </div>

                <!-- A concluída antes de abrir a tela não aparece: o questionário importado já está na lista (e o quadro
                     "Importado pela IA" mostra a importação enquanto não for conferida). -->
                <p v-else-if="atual.situacao === 'erro'" class="flex min-w-0 items-start gap-2 font-semibold text-erro-texto">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v5M12 16h.01" />
                    </svg>
                    <span class="min-w-0 break-words">Erro na importação de “{{ atual.arquivoNome }}”: {{ atual.erro }}</span>
                </p>
            </template>
        </div>

        <Teleport to="body">
        <dialog
            ref="dialogo"
            aria-labelledby="importar-questionario-titulo"
            :aria-describedby="semConfiguracao ? 'importar-questionario-descricao importar-questionario-recusa' : 'importar-questionario-descricao'"
            class="dialogo-retaguarda m-auto w-[min(32rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
            @close="aoFechar"
        >
            <form id="formulario-importar-questionario" novalidate class="flex flex-col gap-5" @submit.prevent.stop="enviar">
                <div>
                    <h2 id="importar-questionario-titulo" class="text-[1.25rem] leading-tight font-bold">Importar questionário com IA</h2>
                    <p id="importar-questionario-descricao" class="mt-2 text-[0.9375rem] text-tinta-suave">
                        A IA lê o espelho do formulário de inscrição e monta as seções e perguntas do questionário. A análise roda em segundo plano e pode
                        levar alguns minutos; você pode continuar usando o sistema.
                    </p>
                </div>

                <AvisoCamposObrigatorios v-if="!semConfiguracao" />

                <div v-if="semConfiguracao" id="importar-questionario-recusa" role="alert" class="flex items-start gap-2 rounded-[12px] bg-nevoa px-4 py-3 font-semibold text-acao">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 8h.01M11 12h1v5h1" />
                    </svg>
                    <p>{{ recusa || SEM_CONFIGURACAO }}</p>
                </div>

                <template v-else>
                    <CampoCadastro id="importar-questionario-arquivo" rotulo="Espelho do formulário" obrigatorio :erro="form.errors.arquivo" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="importar-questionario-arquivo"
                            :accept="TIPOS_ACEITOS"
                            :dica="`PDF ou Word (.docx), até ${tamanhoMaximoMb} MB`"
                            :arquivo="form.arquivo"
                            obrigatorio
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolher"
                        />
                    </CampoCadastro>

                    <Interruptor
                        v-if="temSecoes"
                        id="importar-questionario-substituir"
                        v-model="form.substituir"
                        rotulo="Substituir o questionário atual"
                        ajuda="O questionário atual será apagado e trocado pelo importado."
                        obrigatorio
                        :erro="form.errors.substituir"
                        @change="form.clearErrors('substituir')"
                    />
                </template>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao"
                        @click="fechar"
                    >
                        {{ semConfiguracao ? 'Fechar' : 'Cancelar' }}
                    </button>
                    <button
                        v-if="!semConfiguracao"
                        type="submit"
                        :disabled="form.processing"
                        class="pressionavel inline-flex min-h-11 items-center justify-center rounded-pill bg-acao px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-sobre-acao uppercase hover:bg-acao-escuro disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ textoBotao }}
                    </button>
                </div>
            </form>
        </dialog>
        </Teleport>
    </div>
</template>
