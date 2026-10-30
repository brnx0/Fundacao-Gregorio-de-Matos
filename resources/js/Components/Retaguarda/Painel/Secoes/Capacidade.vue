<script setup lang="ts">
import { computed } from 'vue';
import type { PainelCapacidade } from '../../../../types';
import Colunas from '../Colunas.vue';
import { mesCurto, numero } from '../formatos';
import QuadroPainel from '../QuadroPainel.vue';
import SecaoPainel from '../SecaoPainel.vue';

const props = defineProps<{
    dados: PainelCapacidade;
    atualizando?: boolean;
}>();

const eventosPorMes = computed(() => props.dados.eventos.porMes.map((mes) => ({ rotulo: mesCurto(mes.mes), total: mes.total })));
</script>

<template>
    <SecaoPainel
        id="avaliadores-e-eventos"
        titulo="Avaliadores e eventos"
        descricao="Quem pode avaliar as inscrições e a agenda publicada no portal"
        icone="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"
        :atualizando="atualizando"
    >
        <div class="grid gap-4 @5xl:grid-cols-2">
            <QuadroPainel titulo="Avaliadores" descricao="Quadro atual (os filtros não valem aqui) e a faixa etária de quem está ativo">
                <dl class="grid grid-cols-2 gap-3 @xl:grid-cols-4">
                    <div v-for="item in [
                        { rotulo: 'Ativos', valor: dados.avaliadores.ativos },
                        { rotulo: 'Inativos', valor: dados.avaliadores.inativos },
                        { rotulo: 'Querem avaliar', valor: dados.avaliadores.interessadas },
                        { rotulo: 'Já avaliaram', valor: dados.avaliadores.experientes },
                    ]" :key="item.rotulo" class="rounded-[12px] bg-nevoa px-3 py-2.5">
                        <dt class="text-[0.75rem] text-tinta-suave">{{ item.rotulo }}</dt>
                        <dd class="text-[1.375rem] leading-tight font-bold text-home-tinta">{{ numero(item.valor) }}</dd>
                    </div>
                </dl>
                <p class="text-[0.75rem] text-tinta-suave">"Querem avaliar" e "Já avaliaram" vêm do cadastro das pessoas do portal: é o banco para chamar novos avaliadores.</p>
                <Colunas :itens="dados.avaliadores.idades" legenda="Avaliadores ativos por faixa etária" />
            </QuadroPainel>

            <QuadroPainel titulo="Eventos" descricao="Publicados no portal; por mês, de seis meses atrás a cinco meses à frente">
                <dl class="grid grid-cols-3 gap-3">
                    <div v-for="item in [
                        { rotulo: 'Publicados', valor: dados.eventos.publicados },
                        { rotulo: 'Nos próximos 30 dias', valor: dados.eventos.proximos },
                        { rotulo: 'Já realizados', valor: dados.eventos.realizados },
                    ]" :key="item.rotulo" class="rounded-[12px] bg-nevoa px-3 py-2.5">
                        <dt class="text-[0.75rem] text-tinta-suave">{{ item.rotulo }}</dt>
                        <dd class="text-[1.375rem] leading-tight font-bold text-home-tinta">{{ numero(item.valor) }}</dd>
                    </div>
                </dl>
                <Colunas :itens="eventosPorMes" legenda="Eventos publicados por mês" :destacar-maior="false" />
            </QuadroPainel>
        </div>
    </SecaoPainel>
</template>
