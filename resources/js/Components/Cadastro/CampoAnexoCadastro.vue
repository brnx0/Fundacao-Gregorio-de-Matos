<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import type { AnexoDoCadastro, SituacaoDoDocumento } from '../../types';
import ConfirmarExclusao from '../Retaguarda/ConfirmarExclusao.vue';

const props = defineProps<{
    anexo: AnexoDoCadastro;
    /** Situação do arquivo enviado, que a consulta periódica da tela atualiza; nulo = nada enviado. */
    situacao: SituacaoDoDocumento | null;
    /** Outra requisição da tela em andamento: enquanto durar, nada aqui dispara uma nova (ela cancelaria a primeira). */
    ocupado: boolean;
    /** Erro da tela (obrigatório sem arquivo ao continuar); o do envio tem precedência. */
    erro?: string;
    /** Cadastro finalizado: as mensagens não falam em "continuar". */
    finalizado?: boolean;
    /** O anexo está nas pendências da etapa: o recusado impede de continuar. */
    pendente?: boolean;
}>();

// Mesmos tipos, limite e mensagens do servidor (App\Enums\AnexoCadastro, EnviarAnexoRequest).
const TAMANHO_MAXIMO_MB = 2;
const TIPOS_ACEITOS = props.anexo.somenteImagem ? ['image/jpeg', 'image/png'] : ['application/pdf', 'image/jpeg', 'image/png'];
const EXTENSOES_ACEITAS = props.anexo.somenteImagem ? ['.jpg', '.jpeg', '.png'] : ['.pdf', '.jpg', '.jpeg', '.png'];
const ERRO_ARQUIVO = props.anexo.somenteImagem
    ? `Envie uma imagem JPG ou PNG de até ${TAMANHO_MAXIMO_MB} MB.`
    : `Envie um PDF ou uma imagem JPG ou PNG de até ${TAMANHO_MAXIMO_MB} MB.`;
const DICA = props.anexo.somenteImagem ? `Imagem JPG ou PNG, até ${TAMANHO_MAXIMO_MB} MB.` : `PDF ou imagem (JPG ou PNG), até ${TAMANHO_MAXIMO_MB} MB.`;
// Mesma mensagem do servidor (InscricaoDocumentoController::NAO_CONFERIDO), caso o motivo venha vazio.
const NAO_CONFERIDO = 'Não foi possível conferir o documento agora.';
const ERRO_ENVIO_INTERROMPIDO = 'O envio foi interrompido; envie de novo.';

// O botão principal da linha recebe o foco (links das pendências, fim do envio); o campo de arquivo fica escondido.
const idCampo = `anexo-${props.anexo.campo}`;
const idEntrada = `${idCampo}-arquivo`;
const idDica = `${idCampo}-dica`;
const idMensagem = `${idCampo}-mensagem`;
// Caminho das rotas cadastro.anexos.* (o front não tem helper de rotas nomeadas).
const urlDoAnexo = `/cadastro/anexos/${props.anexo.campo}`;

const formulario = useForm<{ arquivo: File | null }>({ arquivo: null });
const reconferindo = ref<boolean>(false);
const arrastando = ref<boolean>(false);

const limparEntrada = (): void => {
    const entrada = document.getElementById(idEntrada) as HTMLInputElement | null;
    if (entrada) {
        entrada.value = '';
    }
};

// Sem o botão principal na linha (conferência em andamento), o foco vai para a própria linha.
const focarLinha = (): void => {
    void nextTick(() => (document.getElementById(idCampo) ?? document.getElementById(`anexo-card-${props.anexo.campo}`))?.focus());
};

// Durante o envio, a miniatura de uma imagem vem do próprio arquivo escolhido, em data: URL (a CSP aceita em img-src
// 'self' data:, como em CampoArquivo); depois, do arquivo guardado no servidor.
const previaLocal = ref<string | null>(null);
const lerPrevia = (arquivo: File): void => {
    previaLocal.value = null;
    if (!arquivo.type.startsWith('image/')) {
        return;
    }
    const leitor = new FileReader();
    leitor.onload = () => {
        if (formulario.arquivo === arquivo) {
            previaLocal.value = leitor.result as string;
        }
    };
    leitor.readAsDataURL(arquivo);
};

const miniatura = computed<string | null>(() => previaLocal.value ?? (props.anexo.enviado?.imagem ? props.anexo.enviado.url : null));

// O arquivo de tipo não aceito, ou acima de 2 MB, nem sai do navegador; o servidor confere de novo.
const enviar = (arquivo: File | null): void => {
    formulario.clearErrors();
    if (arquivo === null || props.ocupado) {
        return;
    }

    const nome = arquivo.name.toLowerCase();
    const aceito = TIPOS_ACEITOS.includes(arquivo.type) || EXTENSOES_ACEITAS.some((extensao) => nome.endsWith(extensao));
    if (!aceito || arquivo.size > TAMANHO_MAXIMO_MB * 1024 * 1024) {
        formulario.setError('arquivo', ERRO_ARQUIVO);
        limparEntrada();
        focarLinha();
        return;
    }

    formulario.arquivo = arquivo;
    lerPrevia(arquivo);
    formulario.post(urlDoAnexo, {
        forceFormData: true,
        preserveScroll: true,
        // Outra visita (sair da tela, por exemplo) cancela o envio em andamento; a pessoa precisa saber que o arquivo não foi.
        onCancel: () => formulario.setError('arquivo', ERRO_ENVIO_INTERROMPIDO),
        // O foco só volta no fim: durante a requisição os botões ficam desabilitados (e o teclado não volta ao topo).
        onFinish: () => {
            formulario.reset('arquivo');
            previaLocal.value = null;
            limparEntrada();
            focarLinha();
        },
    });
};

const escolherNoSeletor = (evento: Event): void => enviar((evento.target as HTMLInputElement).files?.[0] ?? null);

// "Enviar", "Enviar outro" e "Trocar" abrem o seletor de arquivo; o escolhido substitui o atual.
const abrirSeletor = (): void => {
    document.getElementById(idEntrada)?.click();
};

// Arrastar um arquivo para a linha envia direto, como escolher no seletor.
const arrastarSobre = (evento: DragEvent): void => {
    if (props.ocupado || !evento.dataTransfer?.types.includes('Files')) {
        return;
    }
    evento.preventDefault();
    evento.dataTransfer.dropEffect = 'copy';
    arrastando.value = true;
};

const soltar = (evento: DragEvent): void => {
    arrastando.value = false;
    if (props.ocupado || !evento.dataTransfer?.files.length) {
        return;
    }
    evento.preventDefault();
    enviar(evento.dataTransfer.files[0]);
};

const conferirDeNovo = (): void => {
    router.post(
        `${urlDoAnexo}/conferir`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                reconferindo.value = true;
            },
            onFinish: () => {
                reconferindo.value = false;
            },
        },
    );
};

type Tom = 'andamento' | 'ok' | 'erro' | 'aviso' | 'neutro';

interface Selo {
    tom: Tom;
    texto: string;
}

// O selo à direita do nome: a situação real do arquivo (ou "Pendente" no obrigatório que ainda não foi enviado).
const selo = computed<Selo | null>(() => {
    if (formulario.processing) {
        const progresso = formulario.progress?.percentage;
        return { tom: 'andamento', texto: progresso === undefined ? 'Enviando' : `Enviando ${progresso}%` };
    }

    switch (props.situacao?.situacao) {
        case undefined:
            return props.anexo.obrigatorio ? { tom: 'neutro', texto: 'Pendente' } : null;
        case 'conferindo':
            return { tom: 'andamento', texto: 'Verificando' };
        case 'reprovado':
            return { tom: 'erro', texto: 'Recusado' };
        case 'nao_conferido':
            return { tom: 'aviso', texto: 'Não conferido' };
        default:
            // Aprovado pela IA ou enviado sem conferência: para a pessoa, os dois são só "Recebido".
            return { tom: 'ok', texto: 'Recebido' };
    }
});

const classeDoSelo: Record<Tom, string> = {
    andamento: 'bg-nevoa text-acao',
    ok: 'bg-situacao-aberto/15 text-texto-verde',
    erro: 'bg-erro-fundo text-erro-texto',
    aviso: 'bg-aviso-fundo text-aviso-texto',
    neutro: 'bg-parchment text-tinta-suave',
};

const recusado = computed<boolean>(() => !formulario.processing && props.situacao?.situacao === 'reprovado');
const conferindo = computed<boolean>(() => !formulario.processing && props.situacao?.situacao === 'conferindo');
const naoConferido = computed<boolean>(() => !formulario.processing && props.situacao?.situacao === 'nao_conferido');

// Todo arquivo enviado pode sair; sem o CPF ou o RG, a etapa volta a pedir o documento.
const removivel = computed<boolean>(() => props.anexo.enviado !== null);

// O motivo da IA começa por uma frase curta com o problema ("Não é uma cópia do CPF."): ela vai em destaque.
const motivoDaRecusa = computed<{ destaque: string; resto: string }>(() => {
    const motivo = (props.situacao?.motivo ?? '').trim();
    const partes = motivo.match(/^(.+?[.!?])\s+(.+)$/su);

    return partes ? { destaque: partes[1], resto: partes[2] } : { destaque: motivo || 'Documento recusado.', resto: '' };
});

interface Mensagem {
    tom: 'erro' | 'aviso';
    destaque?: string;
    texto: string;
}

// A linha embaixo do anexo: o erro do envio, a recusa da IA ou a falha da conferência.
const mensagem = computed<Mensagem | null>(() => {
    const erroDoEnvio = formulario.errors.arquivo ?? props.erro;
    if (erroDoEnvio) {
        return { tom: 'erro', texto: erroDoEnvio };
    }

    if (recusado.value) {
        const { destaque, resto } = motivoDaRecusa.value;
        // Fora das pendências (o anexo deixou de ser conferido pela IA), a recusa fica só como aviso.
        const continuar = props.pendente && !props.finalizado ? ' Envie outro arquivo para continuar.' : '';
        return { tom: props.pendente ? 'erro' : 'aviso', destaque, texto: `${resto}${continuar}`.trim() };
    }

    if (naoConferido.value) {
        const falha = (props.situacao?.motivo || NAO_CONFERIDO).trim();
        return { tom: 'aviso', texto: `${falha} ${props.finalizado ? 'A equipe vai conferir depois.' : 'Você pode continuar; a equipe vai conferir depois.'}` };
    }

    return null;
});

const classeDaMensagem = { erro: 'text-erro-texto', aviso: 'text-aviso-texto' };

const classeBotao =
    'pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-[12px] px-4 text-[0.875rem] font-bold whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-60';
const classeLink =
    'pressionavel inline-flex min-h-11 items-center rounded-[12px] px-3 text-[0.875rem] font-bold whitespace-nowrap text-acao hover:bg-nevoa disabled:cursor-not-allowed disabled:opacity-60';
</script>

<template>
    <!-- Uma linha por anexo: ícone, nome e arquivo; a situação; as ações. Embaixo, o motivo da recusa ou o erro. -->
    <div
        :id="`anexo-card-${anexo.campo}`"
        tabindex="-1"
        class="grid grid-cols-[2.75rem_minmax(0,1fr)] items-center gap-x-4 gap-y-3 px-4 py-4 transition-colors focus:outline-none sm:grid-cols-[2.75rem_minmax(0,1fr)_8.5rem_13rem] md:px-6"
        :class="[recusado && pendente ? 'bg-erro-fundo/60' : '', arrastando ? 'bg-nevoa outline-2 -outline-offset-2 outline-acao outline-dashed' : '']"
        @dragenter="arrastarSobre"
        @dragover="arrastarSobre"
        @dragleave="arrastando = false"
        @drop="soltar"
    >
        <!-- O campo nativo fica escondido: os botões da linha abrem o seletor e o arquivo também pode ser arrastado. -->
        <input :id="idEntrada" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="[...TIPOS_ACEITOS, ...EXTENSOES_ACEITAS].join(',')" @change="escolherNoSeletor" />

        <!-- Imagem: a miniatura do próprio arquivo (a do envio em andamento, até o servidor responder); PDF: o ícone. -->
        <span
            aria-hidden="true"
            class="inline-flex size-11 items-center justify-center overflow-hidden rounded-[12px]"
            :class="[
                miniatura ? 'bg-nevoa' : anexo.enviado === null ? 'border border-dashed border-tinta-suave/50 text-tinta-suave' : recusado ? 'bg-erro-fundo text-erro-texto' : 'bg-nevoa text-acao',
                miniatura && recusado ? 'ring-2 ring-erro-borda' : '',
            ]"
        >
            <img v-if="miniatura" :src="miniatura" alt="" class="size-full object-cover" />
            <svg v-else-if="anexo.enviado === null" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M12 5v14M5 12h14" />
            </svg>
            <svg v-else-if="anexo.enviado.imagem" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" />
                <circle cx="9" cy="9" r="2" />
                <path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21" />
            </svg>
            <svg v-else viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                <path d="M14 3v5h5M9 13h6M9 17h6" />
            </svg>
        </span>

        <div class="min-w-0">
            <p class="font-bold text-home-tinta">
                {{ anexo.rotulo
                }}<template v-if="anexo.obrigatorio"><span class="ml-1 text-erro-texto" aria-hidden="true">*</span><span class="sr-only"> (obrigatório)</span></template
                ><span v-else class="font-normal text-tinta-suave"> · opcional</span>
            </p>
            <a
                v-if="anexo.enviado"
                :href="anexo.enviado.url"
                target="_blank"
                rel="noopener"
                class="block truncate text-[0.875rem] text-tinta-suave no-underline hover:text-acao hover:underline"
                :title="anexo.enviado.nome"
            >
                {{ anexo.enviado.nome }}<span class="sr-only"> (abre em nova aba)</span>
            </a>
            <p v-else :id="idDica" class="text-[0.875rem] text-tinta-suave">Nenhum arquivo enviado<span class="sr-only">. {{ DICA }}</span></p>
        </div>

        <!-- Na tela estreita, a situação e as ações descem para a linha de baixo, alinhadas ao nome. -->
        <div class="col-start-2 flex items-center sm:col-start-3" aria-live="polite" aria-atomic="true">
            <span v-if="selo" class="inline-flex items-center gap-1.5 rounded-pill px-3 py-1 text-[0.8125rem] font-semibold whitespace-nowrap" :class="classeDoSelo[selo.tom]">
                <svg v-if="selo.tom === 'andamento'" aria-hidden="true" viewBox="0 0 24 24" class="size-3.5 animate-spin motion-reduce:animate-none" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 3a9 9 0 1 0 9 9" />
                </svg>
                <svg v-else-if="selo.tom === 'ok'" aria-hidden="true" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12 5 5 9-10" />
                </svg>
                <svg v-else-if="selo.tom === 'erro'" aria-hidden="true" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
                <svg v-else-if="selo.tom === 'aviso'" aria-hidden="true" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 7v6M12 17h.01" />
                </svg>
                <span class="sr-only">{{ anexo.rotulo }}: </span>{{ selo.texto }}
            </span>
        </div>

        <div class="col-start-2 flex flex-wrap items-center gap-1 sm:col-start-4 sm:flex-nowrap sm:justify-end">
            <button
                v-if="anexo.enviado === null"
                :id="idCampo"
                type="button"
                :disabled="ocupado"
                :aria-describedby="[idDica, mensagem ? idMensagem : ''].filter(Boolean).join(' ')"
                :class="[classeBotao, 'border border-tinta-suave/60 bg-superficie text-home-tinta hover:border-acao hover:text-acao']"
                @click="abrirSeletor"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 16V4M7 9l5-5 5 5" />
                    <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                </svg>
                Enviar<span class="sr-only"> {{ anexo.rotulo }}</span>
            </button>

            <button
                v-else-if="recusado"
                :id="idCampo"
                type="button"
                :disabled="ocupado"
                :aria-describedby="mensagem ? idMensagem : undefined"
                :class="[classeBotao, 'bg-pilula-fundo text-pilula-texto hover:opacity-90']"
                @click="abrirSeletor"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 16V4M7 9l5-5 5 5" />
                    <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                </svg>
                Enviar outro<span class="sr-only"> arquivo para {{ anexo.rotulo }}</span>
            </button>

            <template v-else-if="!conferindo">
                <button v-if="naoConferido" type="button" :disabled="reconferindo || ocupado" :class="classeLink" @click="conferirDeNovo">
                    {{ reconferindo ? 'Pedindo…' : 'Conferir de novo' }}<span class="sr-only"> {{ anexo.rotulo }}</span>
                </button>
                <button :id="idCampo" type="button" :disabled="ocupado" :class="classeLink" @click="abrirSeletor">
                    Trocar<span class="sr-only"> o arquivo de {{ anexo.rotulo }}</span>
                </button>
            </template>

            <!-- A lixeira em todo arquivo enviado; na conferência em andamento, um X, que cancela e remove o arquivo. -->
            <ConfirmarExclusao
                v-if="removivel"
                icone
                neutro
                :simbolo="conferindo ? 'fechar' : 'lixeira'"
                :url="urlDoAnexo"
                :nome="`o arquivo de ${anexo.rotulo}`"
                acao="Remover"
                :pergunta="`Remover o arquivo de “${anexo.rotulo}”?`"
                :aviso="anexo.obrigatorio ? 'O arquivo será apagado do seu cadastro. Este anexo é obrigatório: envie outro em seguida.' : 'O arquivo será apagado do seu cadastro.'"
                acao-em-andamento="Removendo…"
                :desabilitado="ocupado"
            />
        </div>

        <!-- A região fica sempre na página: o leitor de tela anuncia o que entra nela (recusa, falha, erro do envio). -->
        <div class="col-span-full empty:hidden sm:col-start-2" aria-live="polite" aria-atomic="true">
            <p v-if="mensagem" :id="idMensagem" class="flex items-start gap-2 text-[0.875rem] leading-[1.5]" :class="classeDaMensagem[mensagem.tom]">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 8v4M12 16h.01" />
                </svg>
                <span class="min-w-0 break-words">
                    <strong v-if="mensagem.destaque" class="font-bold">{{ mensagem.destaque }}</strong>
                    {{ mensagem.texto }}
                </span>
            </p>
        </div>
    </div>
</template>
