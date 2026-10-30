<script setup lang="ts">
import { computed } from 'vue';
import { CALOR, degrauDoCalor } from './cores';
import { dataCurta, mesCurto, numero, quantos } from './formatos';
import TabelaLeitor from './TabelaLeitor.vue';

const props = defineProps<{
    /** Primeiro e último dia (AAAA-MM-DD). */
    inicio: string;
    fim: string;
    /** Total por dia (só os dias com algum); o PHP manda [] quando não há nenhum. */
    dias: Record<string, number> | [];
    maximo: number;
    legenda: string;
}>();

type Celula = { dia: string; total: number } | null;

const SEMANA = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
const totalDoDia = (dia: string): number => (Array.isArray(props.dias) ? 0 : (props.dias[dia] ?? 0));

// Meio-dia evita que horário de verão ou fuso troquem o dia ao somar.
const paraData = (dia: string): Date => new Date(`${dia}T12:00:00`);
const paraTexto = (data: Date): string =>
    `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}-${String(data.getDate()).padStart(2, '0')}`;

/** Semanas (colunas) de domingo a sábado; dias fora do período ficam vazios. */
const semanas = computed<Celula[][]>(() => {
    const inicio = paraData(props.inicio);
    const fim = paraData(props.fim);
    const cursor = new Date(inicio);
    cursor.setDate(cursor.getDate() - cursor.getDay());

    const lista: Celula[][] = [];
    while (cursor <= fim) {
        const semana: Celula[] = [];
        for (let indice = 0; indice < 7; indice++) {
            const texto = paraTexto(cursor);
            semana.push(cursor < inicio || cursor > fim ? null : { dia: texto, total: totalDoDia(texto) });
            cursor.setDate(cursor.getDate() + 1);
        }
        lista.push(semana);
    }
    return lista;
});

/** Nome do mês sobre a semana em que ele começa. */
const meses = computed<{ semana: number; rotulo: string }[]>(() =>
    semanas.value.flatMap((semana, indice) => {
        const primeiroDoMes = semana.find((celula) => celula?.dia.endsWith('-01'));
        return primeiroDoMes ? [{ semana: indice, rotulo: mesCurto(primeiroDoMes.dia.slice(0, 7)).slice(0, 3) }] : [];
    }),
);

/** Para o leitor de tela: o total de cada mês (um ano de dias seria longo demais para ouvir). */
const porMes = computed<(string | number)[][]>(() => {
    const totais = new Map<string, number>();
    semanas.value.flat().forEach((celula) => {
        if (celula) totais.set(celula.dia.slice(0, 7), (totais.get(celula.dia.slice(0, 7)) ?? 0) + celula.total);
    });
    return [...totais].map(([mes, total]) => [mesCurto(mes), numero(total)]);
});

const dica = (celula: { dia: string; total: number }): string =>
    `${dataCurta(celula.dia)} · ${celula.total === 0 ? 'nenhuma inscrição' : quantos(celula.total, 'inscrição', 'inscrições')}`;
</script>

<template>
    <!-- Calendário de calor (um quadradinho por dia, semanas em colunas): mais escuro, mais inscrições enviadas no dia.
         Os quadrados crescem com a largura do quadro (até um limite); em tela estreita, a grade rola de lado. -->
    <div class="flex flex-col gap-3">
        <div aria-hidden="true" class="overflow-x-auto pb-1">
            <div
                class="grid max-w-[64rem] gap-[3px]"
                :style="{ gridTemplateColumns: `1rem repeat(${semanas.length}, minmax(11px, 1fr))`, gridTemplateRows: 'auto' }"
            >
                <span
                    v-for="mes in meses"
                    :key="mes.semana"
                    class="text-[0.6875rem] whitespace-nowrap text-tinta-suave"
                    :style="{ gridColumn: `${mes.semana + 2} / span 4`, gridRow: 1 }"
                >{{ mes.rotulo }}</span>
                <span
                    v-for="(letra, indice) in SEMANA"
                    :key="`letra-${indice}`"
                    class="flex items-center text-[0.5625rem] leading-none text-tinta-suave"
                    :style="{ gridColumn: 1, gridRow: indice + 2 }"
                >{{ letra }}</span>
                <template v-for="(semana, coluna) in semanas" :key="coluna">
                    <template v-for="(celula, dia) in semana" :key="dia">
                        <span
                            v-if="celula"
                            class="aspect-square rounded-[2px]"
                            :class="CALOR[degrauDoCalor(celula.total, maximo)].fundo"
                            :style="{ gridColumn: coluna + 2, gridRow: dia + 2 }"
                            :data-dica="dica(celula)"
                        />
                    </template>
                </template>
            </div>
        </div>
        <div aria-hidden="true" class="flex items-center justify-end gap-1.5 text-[0.6875rem] text-tinta-suave">
            Menos
            <span v-for="degrau in [0, 1, 2, 3, 4, 5, 6]" :key="degrau" class="size-[11px] rounded-[2px]" :class="CALOR[degrau].fundo" />
            Mais
        </div>
        <TabelaLeitor :legenda="legenda" :colunas="['Mês', 'Inscrições enviadas']" :linhas="porMes" />
    </div>
</template>
