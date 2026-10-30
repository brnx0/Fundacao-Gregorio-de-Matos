<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { AbaFormulario } from '../../types';

const props = withDefaults(
    defineProps<{
        /** Prefixo dos ids (tab e painel), único na página. */
        id: string;
        abas: AbaFormulario[];
        /** Erros do useForm (campo → mensagem). */
        erros: Partial<Record<string, string>>;
        /** "lateral": coluna à esquerda a partir de lg; abaixo disso, faixa no topo como em "topo". */
        variante?: 'topo' | 'lateral';
        /** Só para ler: os campos dos painéis ficam bloqueados; as abas continuam trocando. */
        somenteLeitura?: boolean;
    }>(),
    { variante: 'topo', somenteLeitura: false },
);

const ativa = defineModel<string>({ required: true });

const botoes = ref<HTMLButtonElement[]>([]);

const errosDa = (aba: AbaFormulario): number => aba.campos.filter((campo) => Boolean(props.erros[campo])).length;

/** Texto do marcador para leitores de tela. */
const textoDoMarcador = (aba: AbaFormulario): string => {
    const erros = errosDa(aba);

    return `${erros} ${erros === 1 ? 'erro' : 'erros'}`;
};

const idAba = (aba: AbaFormulario): string => `${props.id}-aba-${aba.id}`;
const idPainel = (aba: AbaFormulario): string => `${props.id}-painel-${aba.id}`;

// A coluna lateral só existe a partir de lg (1024px); abaixo, as abas voltam para o topo.
const telaLarga = ref<boolean>(false);
const consulta = window.matchMedia('(min-width: 1024px)');
const atualizarTela = (): void => {
    telaLarga.value = consulta.matches;
};
onMounted(() => {
    atualizarTela();
    consulta.addEventListener('change', atualizarTela);
});
onBeforeUnmount(() => consulta.removeEventListener('change', atualizarTela));

const lateral = computed<boolean>(() => props.variante === 'lateral' && telaLarga.value);

// Padrão "tabs" (WAI-ARIA) com ativação automática: setas, Home e End trocam de aba e levam o foco junto.
const irPara = async (indice: number): Promise<void> => {
    const total = props.abas.length;
    const destino = (indice + total) % total;
    const aba = props.abas[destino];
    if (!aba) return;
    ativa.value = aba.id;
    await nextTick();
    botoes.value[destino]?.focus();
};

const tecla = (evento: KeyboardEvent, indice: number): void => {
    const [anterior, proxima] = lateral.value ? ['ArrowUp', 'ArrowDown'] : ['ArrowLeft', 'ArrowRight'];
    const destinos: Record<string, number> = {
        [proxima]: indice + 1,
        [anterior]: indice - 1,
        Home: 0,
        End: props.abas.length - 1,
    };
    const destino = destinos[evento.key];
    if (destino === undefined) return;
    evento.preventDefault();
    void irPara(destino);
};

const classeLista = computed<string>(() =>
    lateral.value
        ? 'painel-retaguarda sticky top-6 flex flex-col gap-1 self-start p-2'
        : '-mx-4 flex gap-1 overflow-x-auto border-b border-hairline px-4 [scrollbar-width:none] lg:mx-0 lg:px-0',
);

const classeAba = computed<string>(() =>
    lateral.value
        ? 'flex min-h-12 w-full items-center gap-3 rounded-[12px] px-4 text-left text-[0.9375rem] font-semibold text-home-tinta hover:bg-nevoa focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-acao aria-selected:bg-acao aria-selected:text-sobre-acao'
        : 'relative -mb-px inline-flex min-h-12 shrink-0 items-center gap-2 border-b-2 border-transparent px-4 text-[0.9375rem] font-semibold whitespace-nowrap text-tinta-suave hover:text-home-tinta focus-visible:rounded-t-[10px] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-acao aria-selected:border-acao aria-selected:text-acao',
);
</script>

<template>
    <!-- Abas de um formulário longo: todos os painéis ficam no DOM (v-show), então nada do que foi digitado se perde. -->
    <div :class="lateral ? 'grid grid-cols-[14rem_minmax(0,1fr)] items-start gap-6' : 'flex flex-col gap-6'">
        <div role="tablist" aria-label="Seções do formulário" :aria-orientation="lateral ? 'vertical' : 'horizontal'" :class="classeLista">
            <button
                v-for="(aba, indice) in abas"
                :id="idAba(aba)"
                :key="aba.id"
                ref="botoes"
                type="button"
                role="tab"
                :aria-selected="ativa === aba.id ? 'true' : 'false'"
                :aria-controls="idPainel(aba)"
                :tabindex="ativa === aba.id ? 0 : -1"
                :class="classeAba"
                @click="ativa = aba.id"
                @keydown="tecla($event, indice)"
            >
                <span v-if="lateral" aria-hidden="true" class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-current/15 text-[0.75rem] font-bold tabular-nums">
                    {{ indice + 1 }}
                </span>
                <span :class="lateral ? 'flex-1' : ''">{{ aba.rotulo }}</span>
                <span
                    v-if="errosDa(aba) > 0"
                    class="inline-flex min-w-5 items-center justify-center rounded-pill bg-invalido-texto px-1.5 text-[0.6875rem] leading-5 font-bold text-superficie tabular-nums"
                >
                    <span aria-hidden="true">{{ errosDa(aba) }}</span><span class="sr-only">{{ `, ${textoDoMarcador(aba)}` }}</span>
                </span>
            </button>
        </div>

        <div class="min-w-0">
            <div
                v-for="aba in abas"
                v-show="ativa === aba.id"
                :id="idPainel(aba)"
                :key="aba.id"
                role="tabpanel"
                :aria-labelledby="idAba(aba)"
                tabindex="0"
                class="focus-visible:rounded-[24px] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-acao"
            >
                <!-- Só para ler, o fieldset bloqueia todos os campos do painel; o texto longo cresce até aparecer inteiro. -->
                <fieldset v-if="somenteLeitura" disabled class="min-w-0 [&_textarea]:field-sizing-content">
                    <slot :name="aba.id" />
                </fieldset>
                <slot v-else :name="aba.id" />
            </div>
        </div>
    </div>
</template>
