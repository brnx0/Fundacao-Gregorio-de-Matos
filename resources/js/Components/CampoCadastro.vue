<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    id: string;
    rotulo: string;
    obrigatorio?: boolean;
    /** Dado do gov.br: o campo vem preenchido e só pode ser lido. */
    govbr?: boolean;
    ajuda?: string;
    erro?: string;
    /** Grupo de opções (rádios, interruptores): vira um fieldset com o rótulo na legend, que descreve o grupo. */
    grupo?: boolean;
}>();

const idAjuda = computed(() => `${props.id}-ajuda`);
const idErro = computed(() => `${props.id}-erro`);
const textoAjuda = computed(() => props.ajuda ?? (props.govbr ? 'Vem do gov.br e não pode ser alterado aqui.' : undefined));

// Repassado ao campo do slot, que liga o texto de ajuda e o erro ao aria-describedby.
const descricao = computed(() => [textoAjuda.value && idAjuda.value, props.erro && idErro.value].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <component :is="grupo ? 'fieldset' : 'div'" class="flex min-w-0 flex-col gap-1.5" :aria-describedby="grupo ? descricao : undefined">
        <!-- A legend não entra no gap do fieldset: o espaço vem da margem. Com o slot "acao" (um botão do campo), ele
             fica na linha do rótulo, à direita, fora do label (botão dentro de label não vale). -->
        <div v-if="$slots.acao" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <label :for="id" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.9375rem] font-semibold">
                <span>
                    {{ rotulo }}<span v-if="obrigatorio" aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
                </span>
            </label>
            <slot name="acao" />
        </div>
        <component
            :is="grupo ? 'legend' : 'label'"
            v-else
            :for="grupo ? undefined : id"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.9375rem] font-semibold"
            :class="{ 'mb-1.5': grupo }"
        >
            <span>
                {{ rotulo }}<span v-if="obrigatorio" aria-hidden="true" class="ml-0.5 text-invalido-texto">*</span>
            </span>
            <span v-if="govbr" class="inline-flex items-center gap-1 rounded-pill bg-nevoa px-2 py-0.5 text-[0.6875rem] font-bold tracking-[0.08em] text-acao uppercase">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="11" width="14" height="10" rx="2" />
                    <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                </svg>
                gov.br
            </span>
        </component>

        <slot :descricao="descricao" :invalido="Boolean(erro)" />

        <p v-if="textoAjuda" :id="idAjuda" class="text-[0.8125rem] leading-[1.5] text-tinta-suave">{{ textoAjuda }}</p>
        <p v-if="erro" :id="idErro" class="flex items-start gap-1.5 text-[0.875rem] font-semibold text-invalido-texto">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="9" />
                <path d="m15 9-6 6M9 9l6 6" />
            </svg>
            {{ erro }}
        </p>
    </component>
</template>
