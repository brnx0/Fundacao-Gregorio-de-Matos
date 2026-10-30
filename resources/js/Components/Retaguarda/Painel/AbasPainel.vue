<script setup lang="ts">
import { nextTick } from 'vue';

type Aba = { id: string; rotulo: string };

const props = defineProps<{
    abas: Aba[];
}>();

const ativa = defineModel<string>({ required: true });

const idAba = (aba: Aba): string => `painel-aba-${aba.id}`;
const idConteudo = (aba: Aba): string => `painel-conteudo-${aba.id}`;

// Padrão "tabs" (WAI-ARIA) com ativação automática, como em AbasFormulario: setas, Home e End trocam de aba e levam o foco junto.
const irPara = async (indice: number): Promise<void> => {
    const total = props.abas.length;
    const aba = props.abas[(indice + total) % total];
    if (!aba) return;
    ativa.value = aba.id;
    await nextTick();
    document.getElementById(idAba(aba))?.focus();
};

const tecla = (evento: KeyboardEvent, indice: number): void => {
    const destinos: Record<string, number> = {
        ArrowRight: indice + 1,
        ArrowLeft: indice - 1,
        Home: 0,
        End: props.abas.length - 1,
    };
    const destino = destinos[evento.key];
    if (destino === undefined) return;
    evento.preventDefault();
    void irPara(destino);
};
</script>

<template>
    <!-- Abas do Painel, uma por assunto, para a tela não virar uma rolagem só. Só a aba aberta fica montada: os gráficos
         nascem já no tamanho certo (o mapa mede o espaço ao abrir, e escondido mediria zero) e os outros não pesam.
         A linha de baixo é uma sombra interna, e não borda: fica sob o sublinhado da aba sem a faixa rolar na vertical. -->
    <div class="flex flex-col gap-6">
        <div
            role="tablist"
            aria-label="Assuntos do painel"
            class="-mx-4 flex gap-1 overflow-x-auto px-4 shadow-[inset_0_-1px_0_var(--color-hairline)] [scrollbar-width:none] @xl:-mx-6 @xl:px-6 @4xl:mx-0 @4xl:px-0"
        >
            <button
                v-for="(aba, indice) in abas"
                :id="idAba(aba)"
                :key="aba.id"
                type="button"
                role="tab"
                :aria-selected="ativa === aba.id ? 'true' : 'false'"
                :aria-controls="idConteudo(aba)"
                :tabindex="ativa === aba.id ? 0 : -1"
                class="inline-flex min-h-12 shrink-0 items-center border-b-2 border-transparent px-4 text-[0.9375rem] font-semibold whitespace-nowrap text-tinta-suave hover:text-home-tinta focus-visible:rounded-t-[10px] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-acao aria-selected:border-acao aria-selected:text-acao"
                @click="ativa = aba.id"
                @keydown="tecla($event, indice)"
            >
                {{ aba.rotulo }}
            </button>
        </div>

        <div
            v-for="aba in abas"
            v-show="ativa === aba.id"
            :id="idConteudo(aba)"
            :key="aba.id"
            role="tabpanel"
            :aria-labelledby="idAba(aba)"
            tabindex="0"
            class="flex flex-col gap-6 focus-visible:rounded-[24px] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-acao"
        >
            <slot v-if="ativa === aba.id" :name="aba.id" />
        </div>
    </div>
</template>
