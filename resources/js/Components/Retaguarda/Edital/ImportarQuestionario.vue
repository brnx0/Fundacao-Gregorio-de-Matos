<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../CampoArquivo.vue';
import CampoCadastro from '../../CampoCadastro.vue';
import Interruptor from '../../Interruptor.vue';
import { focarPrimeiroErro } from '../../focarPrimeiroErro';
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
            if (!props.alteracoesNaoSalvas) recarregarNoQuestionario();
        }
    } catch {
        // Sem conexão: tenta de novo na próxima rodada.
    }
};

let temporizador: ReturnType<typeof setInterval> | undefined;
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
onBeforeUnmount(pararConsulta);

// Recarrega a página já na aba Questionário (?aba=), com o formulário refeito a partir do que o servidor gravou.
const recarregarNoQuestionario = (): void => {
    router.visit(window.location.pathname, { preserveState: false, preserveScroll: true });
};

const verQuestionario = (): void => {
    if (props.alteracoesNaoSalvas && !window.confirm('Há alterações não salvas neste formulário. Ao recarregar, elas serão perdidas. Continuar?')) return;

    recarregarNoQuestionario();
};
</script>

<template>
    <div class="flex flex-col gap-2 sm:max-w-80">
        <button
            type="button"
            :disabled="motivoDoBloqueio !== null"
            :aria-describedby="motivoDoBloqueio !== null ? 'importar-questionario-motivo' : undefined"
            class="pressionavel inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-50"
            @click="abrir"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.94 15.5a2 2 0 0 0-1.44-1.44l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0l1.58 6.13a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0zM20 3v4M22 5h-4M4 17v2M5 18H3" />
            </svg>
            Importar com IA
        </button>

        <p v-if="motivoDoBloqueio !== null" id="importar-questionario-motivo" class="text-[0.8125rem] leading-[1.5] text-tinta-suave">{{ motivoDoBloqueio }}</p>

        <div ref="areaDeSituacao" tabindex="-1" role="status" class="flex flex-col items-start gap-1 text-[0.8125rem] leading-[1.5] outline-offset-4">
            <template v-if="atual">
                <template v-if="analisando">
                    <p class="text-tinta-suave">Analisando “{{ atual.arquivoNome }}”, enviado em {{ atual.enviadaEm }}. Você pode continuar usando o sistema.</p>
                    <p v-if="consultaInterrompida" class="font-semibold text-aviso-texto">Não foi possível acompanhar a análise. Recarregue a página para ver a situação.</p>
                </template>
                <template v-else-if="atual.situacao === 'concluida'">
                    <p v-if="concluidaAgora" class="font-semibold text-texto-verde">Questionário importado. Como há alterações não salvas, ele aparece quando você recarregar.</p>
                    <p v-else class="text-tinta-suave">Última importação: “{{ atual.arquivoNome }}”, concluída (enviada em {{ atual.enviadaEm }}).</p>
                    <button
                        v-if="concluidaAgora"
                        type="button"
                        class="pressionavel inline-flex min-h-11 items-center rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-nevoa"
                        @click="verQuestionario"
                    >
                        Ver o questionário importado
                    </button>
                </template>
                <p v-else class="font-semibold text-erro-texto">Erro na importação de “{{ atual.arquivoNome }}”: {{ atual.erro }}</p>
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
