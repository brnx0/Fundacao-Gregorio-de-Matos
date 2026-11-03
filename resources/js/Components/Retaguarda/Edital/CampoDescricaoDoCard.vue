<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import { SOMENTE_LEITURA, classeTexto } from '../listaEditavel';

const props = defineProps<{
    /** O que a IA usa de contexto, como está no formulário (o edital pode ainda não estar salvo). */
    contexto: { titulo: string; objeto: string; categoria: string };
    obrigatorio: boolean;
    erro?: string;
}>();

const emit = defineEmits<{ limparErro: [] }>();

/** O resumo do edital: a descrição do card. */
const descricao = defineModel<string>({ required: true });

const somenteLeitura = inject(SOMENTE_LEITURA, false);

// O que cabe inteiro nos cards; o servidor recusa mais que isso (Edital::TAMANHO_DO_RESUMO). Texto mais longo só chega
// pela IA ou de antes do limite, e aí a equipe precisa encurtar.
const TAMANHO_MAXIMO = 70;
const tamanho = computed<number>(() => descricao.value.trim().length);
const passouDoLimite = computed<boolean>(() => tamanho.value > TAMANHO_MAXIMO);

// Caminho da rota retaguarda.editais.descricao-ia (o front não tem helper de rotas nomeadas).
const URL_DESCRICAO = '/retaguarda/editais/descricao-ia';
const SESSAO_EXPIRADA = 'Sua sessão expirou. Recarregue a página e entre de novo.';

const ajustando = ref<boolean>(false);
const aviso = ref<{ mensagem: string; erro: boolean } | null>(null);
// O texto de antes do ajuste, para "Desfazer"; some quando a equipe volta a digitar.
const anterior = ref<string | null>(null);

const tokenXsrf = (): string => {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

const mensagemDeFalha = async (resposta: Response): Promise<string> => {
    if (resposta.status === 422) {
        // Falha da IA vem em "erro"; falta de contexto (validação), em "errors".
        const corpo = (await resposta.json().catch(() => ({}))) as { erro?: string; errors?: Record<string, string[]> };
        return corpo.erro ?? Object.values(corpo.errors ?? {})[0]?.[0] ?? 'Confira o texto e tente de novo.';
    }
    if (resposta.status === 429) return 'Muitos ajustes seguidos. Aguarde um minuto e tente de novo.';
    if (resposta.status === 401 || resposta.status === 419) return SESSAO_EXPIRADA;
    return 'Não foi possível ajustar a descrição agora. Tente de novo.';
};

/** Substitui a descrição pela da IA; o texto de antes fica guardado para "Desfazer". */
const ajustar = async (): Promise<void> => {
    ajustando.value = true;
    aviso.value = null;

    try {
        const resposta = await fetch(URL_DESCRICAO, {
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
            body: JSON.stringify({ ...props.contexto, texto: descricao.value }),
        });

        if (resposta.type === 'opaqueredirect') {
            aviso.value = { mensagem: SESSAO_EXPIRADA, erro: true };
        } else if (!resposta.ok) {
            aviso.value = { mensagem: await mensagemDeFalha(resposta), erro: true };
        } else {
            const { descricao: nova } = (await resposta.json()) as { descricao: string };
            anterior.value = descricao.value;
            descricao.value = nova;
            emit('limparErro');
            aviso.value = passouDoLimite.value
                ? { mensagem: `A IA passou de ${TAMANHO_MAXIMO} caracteres. Encurte o texto antes de salvar o edital.`, erro: true }
                : { mensagem: 'Descrição ajustada pela IA. Revise e salve o edital para gravá-la.', erro: false };
        }
    } catch {
        aviso.value = { mensagem: 'Sem conexão com o servidor. Verifique a internet e tente de novo.', erro: true };
    } finally {
        ajustando.value = false;
    }
};

const desfazer = (): void => {
    if (anterior.value === null) return;
    descricao.value = anterior.value;
    anterior.value = null;
    aviso.value = { mensagem: 'O texto de antes voltou.', erro: false };
};

const aoDigitar = (): void => {
    anterior.value = null;
    emit('limparErro');
};

const classeBotaoIa =
    'pressionavel inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-4 text-[0.75rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-wait disabled:opacity-70';
</script>

<template>
    <CampoCadastro
        id="resumo"
        rotulo="Descrição do card"
        :obrigatorio="obrigatorio"
        ajuda="Uma frase curta, que aparece embaixo do título nos cards do portal e no topo da página do edital. Obrigatória para publicar."
        :erro="erro"
    >
        <template v-if="!somenteLeitura" #acao>
            <button type="button" :disabled="ajustando" :class="classeBotaoIa" @click="ajustar">
                <svg v-if="ajustando" aria-hidden="true" viewBox="0 0 24 24" class="size-4 animate-spin motion-reduce:animate-none" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M12 3a9 9 0 1 0 9 9" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" />
                </svg>
                {{ ajustando ? 'Ajustando…' : descricao.trim() ? 'Ajustar com IA' : 'Escrever com IA' }}<span class="sr-only"> a descrição do card</span>
            </button>
        </template>

        <template #default="{ descricao: idsDaDescricao, invalido }">
            <!-- Cresce com o texto (sem rolagem interna) a partir de duas linhas; só passa disso o texto acima do limite. -->
            <textarea
                id="resumo"
                v-model="descricao"
                :required="obrigatorio"
                :maxlength="TAMANHO_MAXIMO"
                :disabled="ajustando"
                :class="classeTexto"
                class="min-h-[5rem] max-h-[22rem] field-sizing-content resize-y"
                :aria-invalid="invalido || undefined"
                :aria-describedby="[idsDaDescricao, 'resumo-tamanho'].filter(Boolean).join(' ')"
                @input="aoDigitar"
            />
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                <p
                    aria-live="polite"
                    class="min-w-0 flex-1 basis-60 text-[0.875rem]"
                    :class="aviso?.erro ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'"
                >
                    <template v-if="aviso">{{ aviso.mensagem }}</template>
                    <button v-if="anterior !== null && !somenteLeitura" type="button" class="ml-1 font-semibold text-acao underline" @click="desfazer">Desfazer</button>
                </p>
                <p id="resumo-tamanho" class="shrink-0 text-[0.8125rem] tabular-nums" :class="passouDoLimite ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'">
                    {{ tamanho }} de até {{ TAMANHO_MAXIMO }} caracteres<span v-if="passouDoLimite"> (encurte para salvar)</span>
                </p>
            </div>
        </template>
    </CampoCadastro>
</template>
