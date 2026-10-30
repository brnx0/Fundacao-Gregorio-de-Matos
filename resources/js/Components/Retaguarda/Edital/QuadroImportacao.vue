<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { ImportacaoDoEdital, PontoDeAtencao } from '../../../types';

const props = defineProps<{
    importacao: ImportacaoDoEdital;
    /** POST que marca a importação como conferida (o quadro some). */
    urlConferida: string;
    /** Ids das abas do formulário, na ordem; o bloco da confiabilidade tem o id da aba. */
    abas: string[];
    /** Texto dos botões que levam ao bloco, quando não é uma aba (ex.: "Ir para as perguntas"); o padrão é "Ir para a aba …". */
    destino?: string;
}>();

const emit = defineEmits<{ irParaAba: [aba: string] }>();

type Nivel = 'alta' | 'media' | 'baixa';

// Alta ≥ 80, média 50–79, baixa < 50. A cor sempre vem com o texto do nível.
const nivel = (nota: number): Nivel => (nota >= 80 ? 'alta' : nota >= 50 ? 'media' : 'baixa');
const ROTULO_NIVEL: Record<Nivel, string> = { alta: 'Alta', media: 'Média', baixa: 'Baixa' };
const COR_TEXTO: Record<Nivel, string> = { alta: 'text-texto-verde', media: 'text-texto-amarelo', baixa: 'text-erro-texto' };
const COR_BARRA: Record<Nivel, string> = { alta: 'bg-texto-verde', media: 'bg-texto-amarelo', baixa: 'bg-erro-texto' };

const geral = computed(() => props.importacao.confiabilidade.geral);

interface GrupoDeAtencao {
    bloco: string | null;
    rotulo: string;
    pontos: PontoDeAtencao[];
}

// Agrupados por bloco na ordem das abas; os sem bloco ("Geral") por último.
const grupos = computed<GrupoDeAtencao[]>(() => {
    const porBloco = new Map<string | null, GrupoDeAtencao>();
    for (const ponto of props.importacao.atencao) {
        const grupo = porBloco.get(ponto.bloco) ?? { bloco: ponto.bloco, rotulo: ponto.blocoRotulo, pontos: [] };
        grupo.pontos.push(ponto);
        porBloco.set(ponto.bloco, grupo);
    }

    const posicao = (bloco: string | null): number => (bloco === null ? Infinity : props.abas.indexOf(bloco));

    return [...porBloco.values()].sort((a, b) => posicao(a.bloco) - posicao(b.bloco));
});

const tokens = computed(() => (props.importacao.tokensTotal !== null ? `${props.importacao.tokensTotal.toLocaleString('pt-BR')} tokens` : null));

const marcando = ref<boolean>(false);

// preserveState: o que já foi editado no formulário continua na tela.
const marcarComoConferida = (): void => {
    router.post(
        props.urlConferida,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                marcando.value = true;
            },
            onFinish: () => {
                marcando.value = false;
            },
        },
    );
};

const classeAcao = 'pressionavel inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-nevoa';
</script>

<template>
    <section aria-labelledby="quadro-importacao-titulo" class="rounded-card border border-acao/40 bg-superficie">
        <div class="flex flex-col gap-4 border-b border-hairline p-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span aria-hidden="true" class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-nevoa text-acao">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z" />
                    </svg>
                </span>
                <div>
                    <h2 id="quadro-importacao-titulo" class="text-[1.25rem] leading-tight font-bold">Importado pela IA</h2>
                    <p class="mt-1 text-[0.9375rem] text-tinta-suave">
                        Confira cada bloco com o PDF antes de publicar. Os pontos de atenção mostram onde a leitura pode ter falhado.
                    </p>
                    <p class="mt-1 text-[0.8125rem] text-tinta-suave tabular-nums">
                        Importado em {{ importacao.importadaEm }} · {{ importacao.modelo }}<template v-if="tokens"> · {{ tokens }}</template>
                    </p>
                </div>
            </div>

            <button
                type="button"
                :disabled="marcando"
                class="pressionavel inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-wait disabled:opacity-70"
                @click="marcarComoConferida"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12 5 5 9-10" />
                </svg>
                {{ marcando ? 'Marcando…' : 'Já conferi' }}
            </button>
        </div>

        <div class="grid gap-5 p-5 md:grid-cols-[11rem_1fr]">
            <div class="flex flex-col gap-1">
                <p class="text-[0.8125rem] font-semibold text-tinta-suave">Confiabilidade geral</p>
                <p v-if="geral !== null" class="flex items-baseline gap-1.5">
                    <span class="text-[2.75rem] leading-none font-bold tabular-nums" :class="COR_TEXTO[nivel(geral)]">{{ geral }}</span>
                    <span class="text-[0.9375rem] text-tinta-suave">de 100</span>
                </p>
                <p v-if="geral !== null" class="text-[0.9375rem] font-semibold" :class="COR_TEXTO[nivel(geral)]">{{ ROTULO_NIVEL[nivel(geral)] }}</p>
                <p v-else class="text-[0.9375rem] text-tinta-suave">A IA não deu notas.</p>
            </div>

            <ul v-if="importacao.confiabilidade.blocos.length > 0" class="grid gap-x-6 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" aria-label="Confiabilidade por bloco">
                <li v-for="bloco in importacao.confiabilidade.blocos" :key="bloco.bloco">
                    <details class="group rounded-[12px] open:bg-parchment">
                        <summary
                            :title="bloco.justificativa || undefined"
                            class="flex min-h-11 cursor-pointer list-none flex-col justify-center gap-1.5 rounded-[12px] px-3 py-2 hover:bg-nevoa [&::-webkit-details-marker]:hidden"
                        >
                            <span class="flex items-baseline justify-between gap-2 text-[0.9375rem]">
                                <span class="inline-flex items-center gap-1 font-semibold">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5 shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m9 6 6 6-6 6" />
                                    </svg>
                                    {{ bloco.rotulo }}
                                </span>
                                <span class="text-[0.8125rem] font-semibold tabular-nums" :class="COR_TEXTO[nivel(bloco.nota)]">
                                    {{ bloco.nota }} · {{ ROTULO_NIVEL[nivel(bloco.nota)] }}
                                </span>
                            </span>
                            <span aria-hidden="true" class="block h-1.5 overflow-hidden rounded-pill bg-hairline">
                                <span class="block h-full rounded-pill" :class="COR_BARRA[nivel(bloco.nota)]" :style="{ width: `${bloco.nota}%` }" />
                            </span>
                        </summary>
                        <div class="flex flex-col items-start gap-1 px-3 pt-1 pb-3 text-[0.875rem]">
                            <p>{{ bloco.justificativa || 'Sem justificativa.' }}</p>
                            <button type="button" :class="classeAcao" class="-ml-3" @click="emit('irParaAba', bloco.bloco)">{{ destino ?? `Ir para a aba ${bloco.rotulo}` }}</button>
                        </div>
                    </details>
                </li>
            </ul>
        </div>

        <div class="border-t border-hairline p-5">
            <h3 class="text-[1.0625rem] font-bold">
                Pontos de atenção <span class="font-normal text-tinta-suave tabular-nums">({{ importacao.atencao.length }})</span>
            </h3>
            <p v-if="grupos.length === 0" class="mt-2 text-[0.9375rem] text-tinta-suave">A IA não apontou nenhum.</p>

            <div v-for="grupo in grupos" :key="grupo.bloco ?? 'geral'" class="mt-4">
                <div class="flex flex-wrap items-center justify-between gap-x-3">
                    <h4 class="text-[0.9375rem] font-semibold">{{ grupo.rotulo }}</h4>
                    <button v-if="grupo.bloco !== null" type="button" :class="classeAcao" @click="emit('irParaAba', grupo.bloco)">
                        <template v-if="destino">{{ destino }}</template>
                        <template v-else>Ir para a aba<span class="sr-only"> {{ grupo.rotulo }}</span></template>
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </button>
                </div>
                <ul class="mt-1 flex flex-col gap-2">
                    <li v-for="(ponto, indice) in grupo.pontos" :key="indice" class="rounded-[12px] border border-hairline px-4 py-3">
                        <p class="text-[0.75rem] font-bold tracking-[0.08em] uppercase" :class="ponto.tipo === 'divergencia' ? 'text-erro-texto' : 'text-aviso-texto'">
                            {{ ponto.tipoRotulo }}
                        </p>
                        <p class="mt-1 text-[0.9375rem]">{{ ponto.texto }}</p>
                        <p v-if="ponto.itens.length > 0" class="mt-1 text-[0.8125rem] text-tinta-suave">
                            {{ ponto.itens.length === 1 ? 'Item citado' : 'Itens citados' }}: {{ ponto.itens.join(', ') }}
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
