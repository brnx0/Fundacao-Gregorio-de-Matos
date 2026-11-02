<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    id: string;
    accept: string;
    /** Formatos e tamanho aceitos, mostrados embaixo da caixa como os textos de ajuda dos outros campos. */
    dica: string;
    arquivo: File | null;
    /** Já existe um arquivo salvo para este campo. */
    enviado?: boolean;
    obrigatorio?: boolean;
    /** aria-describedby vindo do CampoCadastro (a mensagem de erro). */
    descricao?: string;
    invalido?: boolean;
    /** Campo travado: não escolhe nem recebe arquivo arrastado. */
    desabilitado?: boolean;
}>();

const emit = defineEmits<{ escolher: [arquivo: File | null] }>();

const entrada = ref<HTMLInputElement | null>(null);
const arrastando = ref<boolean>(false);
const previa = ref<string | null>(null);

const idEstado = computed(() => `${props.id}-estado`);
const idDica = computed(() => `${props.id}-dica`);
const descricaoCompleta = computed(() =>
    [(props.arquivo || props.enviado) && idEstado.value, idDica.value, props.descricao].filter(Boolean).join(' '),
);

// Quando o arquivo é recusado (acima de 2 MB) ou removido, o campo nativo também é limpo.
// Imagens ganham miniatura em data: URL, que a CSP (img-src 'self' data:) aceita.
watch(
    () => props.arquivo,
    (arquivo) => {
        previa.value = null;
        if (!arquivo) {
            if (entrada.value) entrada.value.value = '';
            return;
        }
        if (!arquivo.type.startsWith('image/')) return;
        const leitor = new FileReader();
        leitor.onload = () => {
            if (props.arquivo === arquivo) previa.value = leitor.result as string;
        };
        leitor.readAsDataURL(arquivo);
    },
);

const escolher = (evento: Event): void => {
    arrastando.value = false;
    emit('escolher', (evento.target as HTMLInputElement).files?.[0] ?? null);
};

// O botão some junto com o arquivo; o foco volta para o campo.
const remover = (): void => {
    emit('escolher', null);
    entrada.value?.focus();
};

const tamanho = (bytes: number): string =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;

const estiloCaixa = computed(() => {
    if (props.invalido) return 'campo-invalido';
    if (props.desabilitado) return `border-tinta-suave/70 bg-superficie opacity-60${props.arquivo || props.enviado ? '' : ' border-dashed'}`;
    if (arrastando.value) return 'border-dashed border-acao bg-nevoa';
    if (props.arquivo || props.enviado) return 'border-tinta-suave/70 bg-superficie hover:border-acao';
    return 'border-dashed border-tinta-suave/70 bg-superficie hover:border-acao hover:bg-nevoa';
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <!-- Mesma altura dos outros campos (h-12). O campo nativo cobre a caixa inteira, transparente: clique,
             arrastar e soltar, teclado e leitor de tela continuam os do navegador. Só o desenho é próprio, porque o
             texto nativo ("Nenhum ficheiro selecionado") segue o idioma do navegador e não pode ser trocado. -->
        <div
            class="relative flex h-12 items-center gap-3 rounded-[12px] border pr-1 pl-2 transition-colors has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-acao motion-reduce:transition-none"
            :class="estiloCaixa"
        >
            <input
                :id="id"
                ref="entrada"
                type="file"
                :accept="accept"
                :required="obrigatorio"
                :disabled="desabilitado"
                title=""
                class="absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                :aria-invalid="invalido || undefined"
                :aria-describedby="descricaoCompleta"
                @change="escolher"
                @dragenter="arrastando = true"
                @dragleave="arrastando = false"
                @drop="arrastando = false"
            />

            <span
                aria-hidden="true"
                class="inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg"
                :class="!arquivo && enviado ? 'bg-situacao-aberto/15 text-situacao-aberto' : 'bg-nevoa text-acao'"
            >
                <img v-if="previa" :src="previa" alt="" class="size-full object-cover" />
                <svg v-else-if="arquivo" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                    <path d="M14 3v5h5" />
                </svg>
                <svg v-else-if="enviado" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12 5 5 9-10" />
                </svg>
                <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 16V4M7 9l5-5 5 5" />
                    <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                </svg>
            </span>

            <!-- Uma linha só. Para o leitor de tela, o estado chega pelo aria-describedby do campo. -->
            <p aria-hidden="true" class="flex min-w-0 flex-1 items-baseline gap-1.5 text-[0.9375rem] whitespace-nowrap">
                <template v-if="arquivo">
                    <!-- relative: o sr-only (absoluto) fica contido no nome cortado; sem isso ele vai para o fim do nome
                         inteiro e cria rolagem horizontal na janela. -->
                    <span :id="idEstado" class="relative truncate font-semibold text-home-tinta">
                        {{ arquivo.name }}<span class="sr-only">, {{ tamanho(arquivo.size) }}</span>
                    </span>
                    <span class="shrink-0 text-[0.8125rem] text-tinta-suave">{{ tamanho(arquivo.size) }}</span>
                </template>
                <template v-else-if="enviado">
                    <span :id="idEstado" class="shrink-0 font-semibold text-home-tinta">Arquivo já enviado</span>
                    <span class="truncate text-tinta-suave">· clique para trocar</span>
                </template>
                <template v-else>
                    <span class="shrink-0 font-semibold text-acao">Escolher arquivo</span>
                    <!-- No celular não se arrasta arquivo, e o texto não cabe. -->
                    <span class="truncate text-tinta-suave max-sm:hidden">ou arraste aqui</span>
                </template>
            </p>

            <button
                v-if="arquivo"
                type="button"
                class="pressionavel relative z-10 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-[9px] px-3 text-[0.75rem] font-bold tracking-[0.1em] text-tinta-suave uppercase hover:bg-erro-fundo hover:text-erro-texto max-sm:px-0"
                @click="remover"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
                <!-- No celular fica só o ×, para sobrar espaço para o nome do arquivo. -->
                <span class="max-sm:sr-only">Remover</span> <span class="sr-only">{{ arquivo.name }}</span>
            </button>
        </div>

        <p :id="idDica" class="text-[0.8125rem] leading-[1.5] text-tinta-suave">{{ dica }}.</p>
    </div>
</template>
