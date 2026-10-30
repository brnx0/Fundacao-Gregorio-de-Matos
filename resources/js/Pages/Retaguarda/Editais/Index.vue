<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ConfirmarExclusao from '../../../Components/Retaguarda/ConfirmarExclusao.vue';
import ImportarEdital from '../../../Components/Retaguarda/Edital/ImportarEdital.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import PainelLista from '../../../Components/Retaguarda/PainelLista.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { EditalRetaguarda, ImportacaoResumo, RetaguardaEditaisIndexProps, SituacaoEdital } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaEditaisIndexProps>();

// Caminhos das rotas retaguarda.editais.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/editais';

const filtros = reactive({ ...props.filtros });

// Só os filtros preenchidos vão para a URL; a lista volta para a página 1.
const filtrar = (): void => {
    const consulta = Object.fromEntries(Object.entries(filtros).filter(([, valor]) => valor !== ''));
    router.get(BASE, consulta, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = (): void => {
    Object.assign(filtros, { busca: '', situacao: '', publicado: '' });
    filtrar();
};

// Ícone do questionário, o da ponta esquerda (da direita para a esquerda: Excluir, Editar, Visualizar, Ocultar
// publicação e ele). Sem categoria ainda não há questionário (as perguntas se vinculam a categorias): cinza, leva à
// aba Categorias do edital. Com categoria, âmbar enquanto não há perguntas e verde com alguma.
const questionario = (edital: EditalRetaguarda): { href: string; classe: string; dica: string } => {
    if (!edital.temCategorias) {
        return { href: `${BASE}/${edital.slug}/edit?aba=categorias`, classe: 'acao-icone-neutro', dica: 'Cadastre uma categoria para criar o questionário' };
    }

    return {
        href: `${BASE}/${edital.slug}/questionario`,
        classe: edital.perguntas > 0 ? 'acao-icone-sucesso' : 'acao-icone-atencao',
        dica: edital.perguntas === 0 ? 'Cadastrar questionário' : `Questionário (${edital.perguntas} ${edital.perguntas === 1 ? 'pergunta' : 'perguntas'})`,
    };
};

const corSituacao: Record<SituacaoEdital, string> = {
    aberto: 'bg-situacao-aberto',
    em_breve: 'bg-situacao-em-breve',
    encerrado: 'bg-situacao-encerrado',
};

// Painel das importações: cópia local, que a consulta periódica atualiza sem recarregar a página.
const URL_IMPORTACOES = `${BASE}/importacoes`;
const INTERVALO_CONSULTA_MS = 10_000;

const importacoes = ref<ImportacaoResumo[]>(props.importacoes.map((importacao) => ({ ...importacao })));
watch(
    () => props.importacoes,
    (novas) => {
        importacoes.value = novas.map((importacao) => ({ ...importacao }));
    },
);

const anuncio = ref<string>('');
const temAnalisando = computed<boolean>(() => importacoes.value.some((importacao) => importacao.situacao === 'analisando'));

type SituacaoConsultada = Pick<ImportacaoResumo, 'situacao' | 'erro' | 'editalUrl'>;

// Consulta só as que estão analisando; uma falha de rede fica para a próxima rodada.
const consultar = async (): Promise<void> => {
    const terminadas: string[] = [];
    let algumaConcluida = false;

    for (const importacao of importacoes.value.filter((item) => item.situacao === 'analisando')) {
        try {
            const resposta = await fetch(`${URL_IMPORTACOES}/${importacao.id}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!resposta.ok) continue;

            const atual = (await resposta.json()) as SituacaoConsultada;
            if (atual.situacao === 'analisando') continue;

            Object.assign(importacao, { situacao: atual.situacao, erro: atual.erro, editalUrl: atual.editalUrl });
            algumaConcluida ||= atual.situacao === 'concluida';
            terminadas.push(
                atual.situacao === 'concluida'
                    ? `A análise de “${importacao.pdfNome}” terminou e o edital foi criado.`
                    : `A análise de “${importacao.pdfNome}” deu erro: ${atual.erro ?? ''}`,
            );
        } catch {
            // Sem conexão: tenta de novo na próxima rodada.
        }
    }

    if (terminadas.length > 0) anuncio.value = terminadas.join(' ');
    // O edital novo entra na tabela.
    if (algumaConcluida) router.reload({ only: ['editais'] });
};

let temporizador: ReturnType<typeof setInterval> | undefined;
const pararConsulta = (): void => {
    clearInterval(temporizador);
    temporizador = undefined;
};
watch(
    temAnalisando,
    (analisando) => {
        if (analisando && temporizador === undefined) {
            temporizador = setInterval(() => void consultar(), INTERVALO_CONSULTA_MS);
        } else if (!analisando) {
            pararConsulta();
        }
    },
    { immediate: true },
);
onBeforeUnmount(pararConsulta);

</script>

<template>
    <PaginaRetaguarda titulo="Editais" descricao="Crie, importe com IA e publique os editais que aparecem no portal.">
        <Head title="Editais · Retaguarda" />

        <template #acoes>
            <ImportarEdital :tamanho-maximo-mb="tamanhoMaximoPdfMb" :configurada="importacaoConfigurada" />
            <Link :href="`${BASE}/create`" class="botao-faixa">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Novo edital
            </Link>
        </template>

        <!-- Avisa o fim das análises a quem usa leitor de tela, sem tirar o foco. -->
        <p class="sr-only" aria-live="polite">{{ anuncio }}</p>

        <section v-if="importacoes.length > 0" aria-labelledby="importacoes-titulo" class="painel-retaguarda overflow-hidden">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-hairline bg-parchment px-5 py-3.5">
                <h2 id="importacoes-titulo" class="font-barlow text-[1.25rem] leading-none font-extrabold tracking-[0.01em] uppercase">Importações do PDF</h2>
                <p class="text-[0.8125rem] text-tinta-suave">Em andamento, com erro e concluídas nas últimas 24 horas.</p>
            </div>

            <ul class="divide-y divide-hairline">
                <li v-for="importacao in importacoes" :key="importacao.id" class="flex flex-col gap-3 px-5 py-3.5 @3xl:flex-row @3xl:items-center @3xl:justify-between">
                    <div class="min-w-0">
                        <p class="truncate font-semibold" :title="importacao.pdfNome">{{ importacao.pdfNome }}</p>
                        <p class="text-[0.8125rem] text-tinta-suave tabular-nums">Enviado em {{ importacao.enviadaEm }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2 @3xl:items-end">
                        <p v-if="importacao.situacao === 'analisando'" class="inline-flex items-center gap-2 text-[0.9375rem] font-semibold text-acao">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 motion-safe:animate-spin" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                <path d="M12 3a9 9 0 1 0 9 9" />
                            </svg>
                            Analisando o edital…
                        </p>

                        <div v-else-if="importacao.situacao === 'concluida'" class="flex flex-wrap items-center gap-x-3 gap-y-1 @3xl:justify-end">
                            <p class="inline-flex items-center gap-2 text-[0.9375rem] font-semibold text-texto-verde">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 12 5 5 9-10" />
                                </svg>
                                Concluída
                            </p>
                            <Link v-if="importacao.editalUrl" :href="importacao.editalUrl" class="acao-icone" data-dica="Abrir edital">
                                <IconeAcao nome="ir" />
                                <span class="sr-only">Abrir edital importado de {{ importacao.pdfNome }}</span>
                            </Link>
                            <span v-else class="text-[0.8125rem] text-tinta-suave">O edital foi excluído.</span>
                        </div>

                        <template v-else>
                            <p class="flex items-start gap-2 text-[0.9375rem] text-erro-texto @3xl:max-w-md">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v5M12 16h.01" />
                                </svg>
                                <span><span class="font-semibold">Erro:</span> {{ importacao.erro }}</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-1 @3xl:justify-end">
                                <Link
                                    :href="`${URL_IMPORTACOES}/${importacao.id}/nova-tentativa`"
                                    method="post"
                                    as="button"
                                    preserve-scroll
                                    class="acao-icone"
                                    data-dica="Tentar de novo"
                                >
                                    <IconeAcao nome="tentar" />
                                    <span class="sr-only">Tentar de novo {{ importacao.pdfNome }}</span>
                                </Link>
                                <ConfirmarExclusao
                                    :url="`${URL_IMPORTACOES}/${importacao.id}`"
                                    :nome="importacao.pdfNome"
                                    acao="Descartar"
                                    acao-em-andamento="Descartando…"
                                    :pergunta="`Descartar a importação de “${importacao.pdfNome}”?`"
                                    aviso="A importação e o PDF enviado são apagados."
                                    icone
                                />
                            </div>
                        </template>
                    </div>
                </li>
            </ul>
        </section>

        <PainelLista
            legenda="Editais"
            :links="editais.links"
            :total="editais.total"
            :de="editais.from"
            :ate="editais.to"
            vazio="Nenhum edital encontrado."
        >
            <template #filtros>
            <form role="search" aria-label="Filtrar editais" class="grid gap-3 @2xl:grid-cols-2 @2xl:items-end @4xl:grid-cols-[1fr_12rem_12rem_auto]" @submit.prevent="filtrar">
                    <div class="flex flex-col gap-1.5">
                        <label for="busca" class="rotulo-retaguarda">Buscar pelo título</label>
                        <input id="busca" v-model="filtros.busca" type="search" maxlength="255" placeholder="Parte do título" class="campo-retaguarda" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="situacao" class="rotulo-retaguarda">Situação</label>
                        <select id="situacao" v-model="filtros.situacao" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todas</option>
                            <option v-for="opcao in situacoes" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="publicado" class="rotulo-retaguarda">Publicação</label>
                        <select id="publicado" v-model="filtros.publicado" class="campo-retaguarda" @change="filtrar">
                            <option value="">Todos</option>
                            <option value="sim">Publicados</option>
                            <option value="nao">Não publicados</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="botao-primario flex-1 @4xl:flex-none">Filtrar</button>
                        <button v-if="filtros.busca || filtros.situacao || filtros.publicado" type="button" class="botao-secundario flex-1 @4xl:flex-none" @click="limpar">Limpar</button>
                    </div>
                </form>
            </template>

            <template #cabecalho>
                <tr>
                    <th scope="col">Título e categoria</th>
                    <th scope="col">Inscrições</th>
                    <th scope="col">Publicado</th>
                    <th scope="col">PDF</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </template>

            <tr v-for="edital in editais.data" :key="edital.slug" class="align-middle">
                <th scope="row" class="min-w-56 text-left font-normal">
                    <span class="block font-semibold">{{ edital.titulo ?? 'Sem título' }}</span>
                    <span class="block text-[0.8125rem] text-tinta-suave">{{ edital.categoria }}</span>
                </th>
                <td class="whitespace-nowrap">
                    <span class="block tabular-nums">{{ edital.periodo ?? 'Sem datas' }}</span>
                    <span v-if="edital.situacao" class="mt-0.5 inline-flex items-center gap-2 text-[0.8125rem] text-tinta-suave">
                        <span aria-hidden="true" class="size-2.5 rounded-full" :class="corSituacao[edital.situacao]" />
                        {{ edital.situacaoRotulo }}
                    </span>
                </td>
                <td>
                    <span class="selo" :class="edital.publicado ? 'selo-positivo' : ''">{{ edital.publicado ? 'Publicado' : 'Não publicado' }}</span>
                </td>
                <td>
                    <span v-if="edital.temPdf">Sim</span>
                    <span v-else class="font-semibold text-erro-texto">Falta</span>
                </td>
                <td class="px-2! py-1!">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="questionario(edital).href" class="acao-icone" :class="questionario(edital).classe" :data-dica="questionario(edital).dica">
                            <IconeAcao nome="questionario" />
                            <span class="sr-only">{{ questionario(edital).dica }} de {{ edital.titulo ?? 'Sem título' }}</span>
                        </Link>
                        <Link
                            :href="`${BASE}/${edital.slug}/publicacao`"
                            method="patch"
                            as="button"
                            preserve-scroll
                            class="acao-icone"
                            :data-dica="edital.publicado ? 'Ocultar publicação' : 'Publicar'"
                        >
                            <IconeAcao :nome="edital.publicado ? 'ocultar' : 'publicar'" />
                            <span class="sr-only">{{ edital.publicado ? 'Ocultar publicação de' : 'Publicar' }} {{ edital.titulo }}</span>
                        </Link>
                        <Link :href="`${BASE}/${edital.slug}`" class="acao-icone" data-dica="Visualizar">
                            <IconeAcao nome="ver" />
                            <span class="sr-only">Visualizar {{ edital.titulo ?? 'Sem título' }}</span>
                        </Link>
                        <Link :href="`${BASE}/${edital.slug}/edit`" class="acao-icone" data-dica="Editar">
                            <IconeAcao nome="editar" />
                            <span class="sr-only">Editar {{ edital.titulo }}</span>
                        </Link>
                        <ConfirmarExclusao
                            :url="`${BASE}/${edital.slug}`"
                            :nome="edital.titulo ?? 'Sem título'"
                            aviso="O edital sai do portal e da lista. Os arquivos continuam guardados."
                            icone
                        />
                    </div>
                </td>
            </tr>
        </PainelLista>
    </PaginaRetaguarda>
</template>
