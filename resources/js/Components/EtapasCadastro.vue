<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

type Etapa = { numero: number; texto: string; href: string | null };

const props = defineProps<{
    // Etapa em que a pessoa está, de 1 a 5; as anteriores aparecem como concluídas.
    atual: number;
    // Cadastro já finalizado (modo edição): as etapas viram abas, todas concluídas, para revisar os dados
    // ou trocar o tipo de cadastro. A conta gov.br sai da linha do tempo: não há o que revisar nela.
    navegavel?: boolean;
}>();

const etapas: Etapa[] = [
    { numero: 1, texto: 'Conta gov.br', href: null },
    { numero: 2, texto: 'Meus dados', href: '/cadastro' },
    { numero: 3, texto: 'Anexos', href: '/cadastro/anexos' },
    { numero: 4, texto: 'Pessoa Jurídica (opcional)', href: '/cadastro/organizacao' },
    { numero: 5, texto: 'Tipo de Cadastro', href: '/cadastro/tipo' },
];

const visiveis = computed(() => (props.navegavel ? etapas.slice(1) : etapas));

const ehAtual = (etapa: Etapa): boolean => etapa.numero === props.atual;
const concluida = (etapa: Etapa): boolean => (props.navegavel ? !ehAtual(etapa) : etapa.numero < props.atual);
const linkDaEtapa = (etapa: Etapa): string | null => (props.navegavel && !ehAtual(etapa) ? etapa.href : null);
</script>

<template>
    <!-- Fica sobre a faixa azul-marinho do portal, em vidro. Cinco etapas: 3 + 2 no tablet, para nenhuma ficar sozinha
         numa linha; as quatro abas do modo edição, 2 + 2. -->
    <nav aria-label="Etapas do cadastro">
        <ol class="vidro grid gap-1 rounded-[22px] p-1.5" :class="navegavel ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3 lg:grid-cols-5'">
            <li v-for="(etapa, i) in visiveis" :key="etapa.texto">
                <component
                    :is="linkDaEtapa(etapa) ? Link : 'div'"
                    :href="linkDaEtapa(etapa) ?? undefined"
                    class="flex min-h-12 items-center gap-3 rounded-[16px] px-3 py-2 no-underline"
                    :class="[
                        ehAtual(etapa) ? 'botao-claro bg-white text-home-azul' : 'text-white/85',
                        linkDaEtapa(etapa) ? 'pressionavel hover:bg-white/15 hover:text-white' : '',
                    ]"
                    :aria-current="ehAtual(etapa) ? 'step' : undefined"
                >
                    <span
                        aria-hidden="true"
                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-full text-[0.75rem] font-bold"
                        :class="{
                            'bg-situacao-aberto text-white': concluida(etapa),
                            'bg-home-azul text-white': ehAtual(etapa),
                            'border border-white/40': !ehAtual(etapa) && !concluida(etapa),
                        }"
                    >
                        <svg v-if="concluida(etapa)" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m5 12 5 5 9-10" />
                        </svg>
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="text-[0.8125rem] leading-tight font-semibold">
                        {{ etapa.texto }}<span v-if="concluida(etapa)" class="sr-only"> (concluída)</span>
                    </span>
                </component>
            </li>
        </ol>
    </nav>
</template>
