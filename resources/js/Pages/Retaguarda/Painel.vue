<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AbasPainel from '../../Components/Retaguarda/Painel/AbasPainel.vue';
import EsqueletoPainel from '../../Components/Retaguarda/Painel/EsqueletoPainel.vue';
import Capacidade from '../../Components/Retaguarda/Painel/Secoes/Capacidade.vue';
import Editais from '../../Components/Retaguarda/Painel/Secoes/Editais.vue';
import Evolucao from '../../Components/Retaguarda/Painel/Secoes/Evolucao.vue';
import Inscricoes from '../../Components/Retaguarda/Painel/Secoes/Inscricoes.vue';
import Organizacoes from '../../Components/Retaguarda/Painel/Secoes/Organizacoes.vue';
import PerfilDosTerritorios from '../../Components/Retaguarda/Painel/Secoes/PerfilDosTerritorios.vue';
import Pessoas from '../../Components/Retaguarda/Painel/Secoes/Pessoas.vue';
import Territorio from '../../Components/Retaguarda/Painel/Secoes/Territorio.vue';
import VisaoGeral from '../../Components/Retaguarda/Painel/Secoes/VisaoGeral.vue';
import PaginaRetaguarda from '../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../Layouts/RetaguardaLayout.vue';
import type { FiltrosPainel, RetaguardaPainelProps } from '../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaPainelProps>();

// Caminho da rota retaguarda.painel (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/painel';
const SEM_FILTRO: FiltrosPainel = { publico: '', territorio: '', edital: '', de: '', ate: '' };

const filtros = reactive<FiltrosPainel>({ ...props.filtros });
watch(
    () => props.filtros,
    (novos) => Object.assign(filtros, novos),
);

const temFiltro = computed<boolean>(() => Object.values(props.filtros).some((valor) => valor !== ''));

const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    Object.assign(filtros, SEM_FILTRO);
    filtrar();
};

// As seções chegam depois da página (deferred). Ao trocar o filtro, os números de antes ficam, esmaecidos, até chegarem
// os novos: o esqueleto só aparece na primeira carga.
type Secao = 'visao' | 'territorio' | 'pessoas' | 'organizacoes' | 'editais' | 'capacidade' | 'inscricoes' | 'evolucao';
const anteriores = reactive<Partial<Pick<RetaguardaPainelProps, Secao>>>({});
const SECOES: Secao[] = ['visao', 'territorio', 'pessoas', 'organizacoes', 'editais', 'capacidade', 'inscricoes', 'evolucao'];
watch(
    () => SECOES.map((secao) => props[secao]),
    () => {
        for (const secao of SECOES) {
            if (props[secao] !== undefined) Object.assign(anteriores, { [secao]: props[secao] });
        }
    },
    { immediate: true },
);
const atualizando = (secao: Secao): boolean => props[secao] === undefined && anteriores[secao] !== undefined;

const visao = computed(() => props.visao ?? anteriores.visao);
const territorio = computed(() => props.territorio ?? anteriores.territorio);
const pessoas = computed(() => props.pessoas ?? anteriores.pessoas);
const organizacoes = computed(() => props.organizacoes ?? anteriores.organizacoes);
const editais = computed(() => props.editais ?? anteriores.editais);
const capacidade = computed(() => props.capacidade ?? anteriores.capacidade);
const inscricoes = computed(() => props.inscricoes ?? anteriores.inscricoes);
const evolucao = computed(() => props.evolucao ?? anteriores.evolucao);

// Uma aba por assunto, para a tela não virar uma rolagem só. Trocar o filtro mantém a aba (a visita preserva o estado);
// "?aba=" abre o painel direto numa delas.
const ABAS = [
    { id: 'visao', rotulo: 'Visão geral' },
    { id: 'territorio', rotulo: 'Território' },
    { id: 'pessoas', rotulo: 'Pessoas e organizações' },
    { id: 'editais', rotulo: 'Editais e inscrições' },
    { id: 'evolucao', rotulo: 'Evolução' },
    { id: 'avaliadores', rotulo: 'Avaliadores e eventos' },
];
const abaDaUrl = new URLSearchParams(window.location.search).get('aba');
const abaAtiva = ref<string>(ABAS.some((aba) => aba.id === abaDaUrl) ? (abaDaUrl as string) : 'visao');
</script>

<template>
    <PaginaRetaguarda titulo="Painel" descricao="Quem se cadastra, onde mora e como os editais são procurados: números para orientar políticas e investimentos culturais.">
        <Head title="Painel · Retaguarda" />

        <template #detalhe>
            <span v-if="visao" class="selo-faixa">Números de {{ visao.atualizadoEm }} · o painel recalcula a cada {{ minutosNoCache }} minutos</span>
        </template>

        <form
            role="search"
            aria-label="Filtros do painel"
            class="painel-retaguarda grid gap-4 p-4 @xl:p-5 @3xl:grid-cols-2 @6xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,10rem)_minmax(0,10rem)_auto]"
            @submit.prevent="filtrar"
        >
            <div class="flex flex-col gap-1.5">
                <label for="filtro-publico" class="rotulo-retaguarda">Público</label>
                <select id="filtro-publico" v-model="filtros.publico" class="campo-retaguarda" @change="filtrar">
                    <option value="">Proponentes e patrocinadores</option>
                    <option v-for="opcao in opcoes.publicos" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="filtro-territorio" class="rotulo-retaguarda">Território</label>
                <select id="filtro-territorio" v-model="filtros.territorio" class="campo-retaguarda" @change="filtrar">
                    <option value="">Todos</option>
                    <option v-for="opcao in opcoes.territorios" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="filtro-edital" class="rotulo-retaguarda">Edital</label>
                <select id="filtro-edital" v-model="filtros.edital" class="campo-retaguarda" aria-describedby="filtro-edital-ajuda" @change="filtrar">
                    <option value="">Todos os editais</option>
                    <option v-for="opcao in opcoes.editais" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                </select>
                <span id="filtro-edital-ajuda" class="sr-only">Com um edital, o perfil mostra só quem enviou inscrição nele.</span>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="filtro-de" class="rotulo-retaguarda">De</label>
                <input id="filtro-de" v-model="filtros.de" type="date" class="campo-retaguarda" />
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="filtro-ate" class="rotulo-retaguarda">Até</label>
                <input id="filtro-ate" v-model="filtros.ate" type="date" class="campo-retaguarda" />
            </div>
            <div class="flex items-end gap-2 @3xl:col-span-2 @6xl:col-span-1">
                <button type="submit" class="botao-primario flex-1 @6xl:flex-none">Filtrar</button>
                <button v-if="temFiltro" type="button" class="botao-secundario flex-1 @6xl:flex-none" @click="limpar">Limpar</button>
            </div>
        </form>

        <AbasPainel v-model="abaAtiva" :abas="ABAS">
            <template #visao>
                <VisaoGeral v-if="visao" :dados="visao" :atualizando="atualizando('visao')" />
                <EsqueletoPainel v-else secao="a visão geral" forma="cartoes" />
            </template>

            <template #territorio>
                <Territorio v-if="territorio" :dados="territorio" :atualizando="atualizando('territorio')" />
                <EsqueletoPainel v-else secao="o território" forma="mapa" />

                <!-- O perfil de cada Prefeitura-Bairro chega com a evolução, mas o assunto é o território. -->
                <PerfilDosTerritorios v-if="evolucao" :dados="evolucao.territorios" :base-minima="baseMinima" :atualizando="atualizando('evolucao')" />
                <EsqueletoPainel v-else secao="o perfil dos territórios" />
            </template>

            <template #pessoas>
                <Pessoas v-if="pessoas" :dados="pessoas" :atualizando="atualizando('pessoas')" />
                <EsqueletoPainel v-else secao="o perfil das pessoas" />

                <Organizacoes v-if="organizacoes" :dados="organizacoes" :atualizando="atualizando('organizacoes')" />
                <EsqueletoPainel v-else secao="as organizações" />
            </template>

            <template #editais>
                <Editais v-if="editais" :dados="editais" :atualizando="atualizando('editais')" />
                <EsqueletoPainel v-else secao="os editais" forma="cartoes" />

                <Inscricoes v-if="inscricoes" :dados="inscricoes" :atualizando="atualizando('inscricoes')" />
                <EsqueletoPainel v-else secao="as inscrições" forma="cartoes" />
            </template>

            <template #evolucao>
                <Evolucao v-if="evolucao" :dados="evolucao" :atualizando="atualizando('evolucao')" />
                <EsqueletoPainel v-else secao="a evolução" />
            </template>

            <template #avaliadores>
                <Capacidade v-if="capacidade" :dados="capacidade" :atualizando="atualizando('capacidade')" />
                <EsqueletoPainel v-else secao="avaliadores e eventos" />
            </template>
        </AbasPainel>

        <p class="text-center text-[0.75rem] text-tinta-suave">
            O painel mostra só totais e percentuais, nunca uma pessoa.
            <template v-if="visao">Números de {{ visao.atualizadoEm }}; o painel recalcula a cada {{ minutosNoCache }} minutos.</template>
        </p>
    </PaginaRetaguarda>
</template>
