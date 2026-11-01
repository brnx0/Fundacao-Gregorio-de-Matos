<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import CampoCadastro from '../../../Components/CampoCadastro.vue';
import Interruptor from '../../../Components/Interruptor.vue';
import AbasDaAvaliacao from '../../../Components/Retaguarda/Avaliacao/AbasDaAvaliacao.vue';
import TransferirInscricoes from '../../../Components/Retaguarda/Avaliacao/TransferirInscricoes.vue';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import ConfirmarExclusao from '../../../Components/Retaguarda/ConfirmarExclusao.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { AvaliadorDoEdital, AvaliadorParaTransferir, RetaguardaAvaliacaoAvaliadoresProps, SituacaoConvite } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaAvaliacaoAvaliadoresProps>();

// Caminhos das rotas retaguarda.avaliacao.index e retaguarda.avaliacao.avaliadores.* (store, categorias, destroy,
// convite e novo-link); o front não tem helper de rotas nomeadas.
const LISTA = '/retaguarda/avaliacao';
const BASE = `${LISTA}/editais/${props.edital.slug}/avaliadores`;
const FORMULARIO = '#formulario-incluir-avaliadores';

// Incluir: os interruptores marcam quem entra; a busca só filtra a lista (os já marcados continuam marcados).
const form = useForm<{ avaliadores: number[] }>({ avaliadores: [] });
const busca = ref<string>('');

const disponiveisFiltrados = computed(() => {
    const termo = busca.value.trim().toLocaleLowerCase('pt-BR');

    return termo === '' ? props.disponiveis : props.disponiveis.filter((avaliador) => avaliador.nome.toLocaleLowerCase('pt-BR').includes(termo));
});

const escolhido = (id: number): boolean => form.avaliadores.includes(id);

const escolher = (id: number, ligado: boolean): void => {
    form.avaliadores = ligado ? [...form.avaliadores, id] : form.avaliadores.filter((outro) => outro !== id);
    form.clearErrors();
};

// O servidor pode recusar a lista toda ("avaliadores") ou um item dela ("avaliadores.0").
const erroDaInclusao = computed<string | undefined>(() => {
    const erros = form.errors as Record<string, string | undefined>;

    return erros.avaliadores ?? Object.entries(erros).find(([campo]) => campo.startsWith('avaliadores.'))?.[1];
});

const incluir = (): void => {
    form.post(BASE, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            busca.value = '';
        },
        onError: () => focarPrimeiroErro(FORMULARIO),
    });
};

// Categorias de cada avaliador: cada interruptor grava na hora a lista nova; enquanto grava, os dele ficam travados.
const salvandoCategorias = reactive<Record<number, boolean>>({});
const erroDasCategorias = reactive<Record<number, string | undefined>>({});

const alternarCategoria = (avaliador: AvaliadorDoEdital, categoria: number, ligado: boolean): void => {
    const categorias = ligado ? [...avaliador.categorias, categoria] : avaliador.categorias.filter((outra) => outra !== categoria);

    router.put(
        `${BASE}/${avaliador.id}/categorias`,
        { categorias },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                salvandoCategorias[avaliador.id] = true;
                erroDasCategorias[avaliador.id] = undefined;
            },
            onError: (erros) => {
                erroDasCategorias[avaliador.id] = erros.categorias ?? Object.values(erros)[0];
            },
            onFinish: () => {
                salvandoCategorias[avaliador.id] = false;
            },
        },
    );
};

const nomesDasCategorias = (avaliador: AvaliadorDoEdital): string =>
    props.categorias
        .filter((categoria) => avaliador.categorias.includes(categoria.id))
        .map((categoria) => categoria.nome)
        .join(', ');

/** Enviado em verde; na fila pede atenção; falhou fica no cinza, com o motivo. */
const classeDoConvite = (situacao: SituacaoConvite | null): string => {
    if (situacao === 'enviado') {
        return 'selo-positivo';
    }

    return situacao === 'na_fila' ? 'selo-atencao' : '';
};

const plural = (quantidade: number, singular: string, varios: string): string => `${quantidade} ${quantidade === 1 ? singular : varios}`;

// Atalho do aviso do desativado: as inscrições não concluídas dele vão para um avaliador ativo do edital (a lista já
// vem pelo nome).
const destinos = computed<AvaliadorParaTransferir[]>(() =>
    props.avaliadores.filter((avaliador) => avaliador.ativo).map((avaliador) => ({ avaliadorId: avaliador.avaliadorId, nome: avaliador.nome })),
);

// Link novo: confirma antes, porque o link atual (e quem já entrou com ele) deixa de valer na hora.
const dialogoNovoLink = ref<HTMLDialogElement | null>(null);
const paraNovoLink = ref<AvaliadorDoEdital | null>(null);
const gerandoLink = ref<boolean>(false);

const abrirNovoLink = (avaliador: AvaliadorDoEdital): void => {
    paraNovoLink.value = avaliador;
    dialogoNovoLink.value?.showModal();
};

const fecharNovoLink = (): void => dialogoNovoLink.value?.close();

const gerarNovoLink = (): void => {
    if (!paraNovoLink.value) return;

    router.post(
        `${BASE}/${paraNovoLink.value.id}/novo-link`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                gerandoLink.value = true;
            },
            onFinish: () => {
                gerandoLink.value = false;
                void nextTick(fecharNovoLink);
            },
        },
    );
};

const classeCancelar =
    'pressionavel inline-flex min-h-11 items-center justify-center rounded-pill border border-tinta-suave/70 bg-superficie px-5 text-[0.8125rem] font-bold tracking-[0.12em] uppercase hover:border-acao hover:text-acao';
</script>

<template>
    <PaginaRetaguarda
        :titulo="edital.titulo"
        descricao="Quem avalia as inscrições homologadas deste edital, as categorias de cada um e o convite por e-mail com o link pessoal."
        :trilha="[{ texto: edital.titulo }]"
    >
        <Head :title="`Avaliadores · ${edital.titulo} · Avaliação · Retaguarda`" />

        <template #detalhe>
            <span v-for="(periodo, indice) in edital.periodos" :key="indice" class="selo-faixa">Avaliação: {{ periodo }}</span>
        </template>

        <template #acoes>
            <Link
                :href="LISTA"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M11 18l-6-6 6-6" />
                </svg>
                Voltar para os editais
            </Link>
        </template>

        <AbasDaAvaliacao :edital="edital.slug" atual="avaliadores" />

        <div v-if="pendencias.length > 0" role="note" aria-labelledby="pendencias-titulo" class="rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
            <h2 id="pendencias-titulo" class="text-[0.9375rem] font-bold">Antes de distribuir as inscrições</h2>
            <ul class="mt-2 flex list-disc flex-col gap-1 pl-5 text-[0.9375rem] leading-[1.5]">
                <li v-for="pendencia in pendencias" :key="pendencia">{{ pendencia }}</li>
            </ul>
        </div>

        <PainelLista
            legenda="Avaliadores do edital"
            :links="[]"
            :total="avaliadores.length"
            :de="avaliadores.length > 0 ? 1 : null"
            :ate="avaliadores.length > 0 ? avaliadores.length : null"
            vazio="Nenhum avaliador neste edital. Inclua os avaliadores abaixo."
        >
            <template #cabecalho>
                <tr>
                    <th scope="col">Avaliador</th>
                    <th scope="col">Categorias</th>
                    <th scope="col">Convite</th>
                    <th scope="col" class="text-right">Inscrições</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="avaliador in avaliadores" :key="avaliador.id" class="align-top">
                <th scope="row" class="min-w-56 text-left font-normal">
                    <span class="block font-semibold">{{ avaliador.nome }}</span>
                    <span class="block text-[0.8125rem] break-all text-tinta-suave">{{ avaliador.email }}</span>
                    <span v-if="!avaliador.ativo" class="selo mt-1.5">Desativado</span>
                    <div
                        v-if="!avaliador.ativo && avaliador.naoConcluidas > 0"
                        role="note"
                        class="mt-2 max-w-72 rounded-[12px] border border-aviso-texto/30 bg-aviso-fundo px-3 py-2 text-[0.8125rem] leading-[1.5] text-aviso-texto"
                    >
                        <p class="font-semibold">
                            Desativado com {{ plural(avaliador.naoConcluidas, 'inscrição não concluída', 'inscrições não concluídas') }} neste edital. Transfira-as para outro
                            avaliador.
                        </p>
                        <div class="mt-2 text-home-tinta">
                            <TransferirInscricoes
                                :id="`transferir-${avaliador.id}`"
                                :edital="edital.slug"
                                :de="{ avaliadorId: avaliador.avaliadorId, nome: avaliador.nome }"
                                :destinos="destinos"
                                empilhado
                            />
                        </div>
                    </div>
                </th>
                <td class="min-w-56">
                    <fieldset v-if="edital.temCategorias" class="min-w-0" :aria-describedby="erroDasCategorias[avaliador.id] ? `categorias-${avaliador.id}-erro` : undefined">
                        <legend class="sr-only">Categorias que {{ avaliador.nome }} avalia</legend>
                        <Interruptor
                            v-for="categoria in categorias"
                            :id="`categoria-${avaliador.id}-${categoria.id}`"
                            :key="categoria.id"
                            :model-value="avaliador.categorias.includes(categoria.id)"
                            :rotulo="categoria.nome"
                            variante="linha"
                            :desabilitado="salvandoCategorias[avaliador.id]"
                            :invalido="Boolean(erroDasCategorias[avaliador.id])"
                            @update:model-value="(ligado: boolean) => alternarCategoria(avaliador, categoria.id, ligado)"
                        />
                        <p v-if="erroDasCategorias[avaliador.id]" :id="`categorias-${avaliador.id}-erro`" role="alert" class="mt-1 text-[0.875rem] font-semibold text-invalido-texto">
                            {{ erroDasCategorias[avaliador.id] }}
                        </p>
                        <p v-else-if="avaliador.categorias.length === 0" class="mt-1 text-[0.8125rem] text-tinta-suave">Nenhuma categoria marcada.</p>
                        <p v-else class="sr-only">Marcadas: {{ nomesDasCategorias(avaliador) }}.</p>
                    </fieldset>
                    <span v-else class="text-tinta-suave">O edital não tem categorias.</span>
                </td>
                <td class="min-w-48">
                    <template v-if="avaliador.convite.situacao">
                        <span class="selo" :class="classeDoConvite(avaliador.convite.situacao)">{{ avaliador.convite.rotulo }}</span>
                        <span v-if="avaliador.convite.enviadoEm" class="mt-1 block text-[0.8125rem] text-tinta-suave tabular-nums">em {{ avaliador.convite.enviadoEm }}</span>
                        <span v-if="avaliador.convite.erro" class="mt-1 block max-w-64 text-[0.8125rem] leading-[1.5] text-invalido-texto">{{ avaliador.convite.erro }}</span>
                    </template>
                    <span v-else class="text-tinta-suave">Não enviado</span>
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">
                    <span class="block">{{ avaliador.inscricoes }}</span>
                    <span v-if="avaliador.naoConcluidas > 0" class="block text-[0.8125rem] text-tinta-suave">{{ plural(avaliador.naoConcluidas, 'não concluída', 'não concluídas') }}</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link
                            :href="`${BASE}/${avaliador.id}/convite`"
                            method="post"
                            as="button"
                            preserve-scroll
                            :disabled="!avaliador.ativo"
                            class="acao-icone disabled:cursor-not-allowed disabled:opacity-50"
                            :data-dica="avaliador.ativo ? 'Reenviar convite' : 'Desativado: sem convite'"
                        >
                            <IconeAcao nome="tentar" />
                            <span class="sr-only">Reenviar o convite para {{ avaliador.nome }}</span>
                        </Link>
                        <button
                            type="button"
                            :disabled="!avaliador.ativo"
                            class="acao-icone disabled:cursor-not-allowed disabled:opacity-50"
                            :data-dica="avaliador.ativo ? 'Gerar link novo' : 'Desativado: sem link'"
                            @click="abrirNovoLink(avaliador)"
                        >
                            <IconeAcao nome="link" />
                            <span class="sr-only">Gerar um link novo para {{ avaliador.nome }}</span>
                        </button>
                        <ConfirmarExclusao
                            :url="`${BASE}/${avaliador.id}`"
                            :nome="avaliador.nome"
                            icone
                            acao="Tirar do edital"
                            acao-em-andamento="Tirando…"
                            :pergunta="`Tirar ${avaliador.nome} deste edital?`"
                            aviso="O avaliador continua no Cadastro. Quem tem inscrições do edital só sai depois de transferi-las."
                        />
                    </div>
                </td>
            </tr>
        </PainelLista>

        <section aria-labelledby="incluir-titulo" class="painel-retaguarda p-4 @3xl:p-6">
            <h2 id="incluir-titulo" class="font-barlow text-[1.5rem] leading-none font-extrabold tracking-[0.01em] uppercase">Incluir avaliadores</h2>
            <p class="mt-2 text-[0.9375rem] leading-[1.6] text-tinta-suave">
                Avaliadores ativos do Cadastro que ainda não estão neste edital. Cada um recebe por e-mail o convite com o link pessoal.
            </p>

            <p v-if="disponiveis.length === 0" class="mt-5 font-semibold text-home-tinta">
                Todos os avaliadores ativos do Cadastro já estão neste edital. Cadastre outros em Cadastro → Avaliadores.
            </p>

            <form v-else id="formulario-incluir-avaliadores" novalidate class="mt-5 flex flex-col gap-5" @submit.prevent="incluir">
                <div class="flex max-w-md flex-col gap-1.5">
                    <label for="busca-disponiveis" class="rotulo-retaguarda">Filtrar pelo nome</label>
                    <input id="busca-disponiveis" v-model="busca" type="search" maxlength="120" placeholder="Parte do nome" class="campo-retaguarda" />
                </div>

                <CampoCadastro id="avaliadores" rotulo="Avaliadores a incluir" obrigatorio grupo :erro="erroDaInclusao" v-slot="{ descricao, invalido }">
                    <div class="max-h-96 overflow-y-auto rounded-[12px] border border-hairline px-4 py-1" :class="{ 'campo-invalido': invalido }">
                        <Interruptor
                            v-for="avaliador in disponiveisFiltrados"
                            :id="`incluir-${avaliador.id}`"
                            :key="avaliador.id"
                            :model-value="escolhido(avaliador.id)"
                            :rotulo="avaliador.nome"
                            :ajuda="`CPF ${avaliador.cpf}`"
                            variante="linha"
                            :invalido="invalido"
                            :descricao="descricao"
                            @update:model-value="(ligado: boolean) => escolher(avaliador.id, ligado)"
                        />
                        <p v-if="disponiveisFiltrados.length === 0" class="py-3 text-[0.9375rem] text-tinta-suave">Nenhum avaliador com esse nome.</p>
                    </div>
                </CampoCadastro>

                <BarraAcoes
                    :salvar="form.processing ? 'Incluindo…' : form.avaliadores.length > 1 ? `Incluir ${form.avaliadores.length} e enviar o convite` : 'Incluir e enviar o convite'"
                    :processando="form.processing"
                    :com-erros="Boolean(erroDaInclusao)"
                    mensagem-erro="Confira a escolha dos avaliadores."
                />
            </form>
        </section>

        <dialog
            ref="dialogoNovoLink"
            aria-labelledby="novo-link-titulo"
            aria-describedby="novo-link-aviso"
            class="dialogo-retaguarda m-auto w-[min(30rem,calc(100vw-2rem))] rounded-card border border-hairline bg-superficie p-6 text-home-tinta backdrop:bg-noite/60"
        >
            <h2 id="novo-link-titulo" class="text-[1.25rem] leading-tight font-bold">Gerar um link novo para {{ paraNovoLink?.nome }}?</h2>
            <p id="novo-link-aviso" class="mt-2 text-[0.9375rem] text-tinta-suave">
                O link atual deixa de valer na hora, até para quem já entrou com ele. O novo vai por e-mail para {{ paraNovoLink?.email }}, com todos os editais do
                avaliador.
            </p>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" autofocus :class="classeCancelar" @click="fecharNovoLink">Cancelar</button>
                <button type="button" :disabled="gerandoLink" class="botao-primario" @click="gerarNovoLink">
                    {{ gerandoLink ? 'Gerando…' : 'Gerar e enviar' }}
                </button>
            </div>
        </dialog>
    </PaginaRetaguarda>
</template>
