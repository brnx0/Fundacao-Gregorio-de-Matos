<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { textoDaResposta } from '../../../Components/Inscricao/questionario';
import DecisaoDaHomologacao from '../../../Components/Retaguarda/Homologacao/DecisaoDaHomologacao.vue';
import JulgamentoDoRecurso from '../../../Components/Retaguarda/Homologacao/JulgamentoDoRecurso.vue';
import MarcacaoDoItem from '../../../Components/Retaguarda/Homologacao/MarcacaoDoItem.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { MarcacaoConferida, RetaguardaHomologacaoShowProps, SituacaoConferencia } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaHomologacaoShowProps>();

// Caminho da rota retaguarda.homologacao.index (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/homologacao';

// Só as perguntas que entram na conferência (as visíveis pela condição); seção sem nenhuma não aparece.
const secoesConferidas = computed(() => {
    const conferidas = new Set(props.perguntasConferidas);
    return props.secoes
        .map((secao) => ({ ...secao, perguntas: secao.perguntas.filter((pergunta) => conferidas.has(pergunta.id)) }))
        .filter((secao) => secao.perguntas.length > 0);
});

// Quantos itens já têm marcação e quantos estão com problema, para o resumo da decisão.
const andamento = computed(() => {
    const marcacoes: (MarcacaoConferida | null)[] = [
        ...props.documentos.map((documento) => documento.marcacao),
        ...props.perguntasConferidas.map((id) => props.marcacoesDasPerguntas[id] ?? null),
    ];
    return {
        total: marcacoes.length,
        conferidos: marcacoes.filter((marcacao) => marcacao !== null).length,
        comProblema: marcacoes.filter((marcacao) => marcacao?.situacao === 'problema').length,
    };
});

const resumoDoAndamento = computed<string>(() => {
    const { total, conferidos, comProblema } = andamento.value;
    const itens = `${conferidos} de ${total} ${total === 1 ? 'item conferido' : 'itens conferidos'}`;
    return comProblema > 0 ? `${itens}, ${comProblema} com problema.` : `${itens}.`;
});

const periodoDeHomologacao = computed<string>(() => (props.periodo.inicio && props.periodo.fim ? `${props.periodo.inicio} a ${props.periodo.fim}` : 'Não cadastrado'));

// Ponto do selo da situação: homologada em verde; sem decisão e em recurso pedem atenção; o resto em cinza.
const corDaSituacao = computed<string>(() => {
    if (props.inscricao.homologacao === 'homologada') {
        return 'bg-situacao-aberto';
    }
    return props.inscricao.homologacao === null || props.inscricao.homologacao === 'em_recurso' ? 'bg-situacao-em-breve' : 'bg-situacao-encerrado';
});

const classeDoParecerDaIa = (situacao: SituacaoConferencia): string => {
    if (situacao === 'aprovado') {
        return 'selo-positivo';
    }
    return situacao === 'reprovado' || situacao === 'nao_conferido' ? 'selo-atencao' : '';
};

const ICONES = {
    escolhas: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
    documentos: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    questionario:
        'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM12 11h4M12 16h4M8 11h.01M8 16h.01',
    decisao: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
    recurso: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5',
};

const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
</script>

<template>
    <PaginaRetaguarda
        largura="estreita"
        :titulo="`Inscrição ${inscricao.numero}`"
        :descricao="`${inscricao.proponente} · ${inscricao.edital}`"
        :trilha="[{ texto: inscricao.numero }]"
    >
        <Head :title="`Inscrição ${inscricao.numero} · Homologação · Retaguarda`" />

        <template #detalhe>
            <span class="selo-faixa">
                <span aria-hidden="true" class="size-2 rounded-full" :class="corDaSituacao" />
                {{ inscricao.situacaoRotulo }}
            </span>
            <span v-if="inscricao.prazoDoRecurso" class="selo-faixa">Recurso até {{ inscricao.prazoDoRecurso }}</span>
        </template>

        <template #acoes>
            <Link
                :href="BASE"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M11 18l-6-6 6-6" />
                </svg>
                Voltar para a lista
            </Link>
        </template>

        <div v-if="aviso" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="11" width="14" height="10" rx="2" />
                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
            </svg>
            <p class="text-[0.9375rem] leading-[1.6] font-semibold">{{ aviso }}</p>
        </div>

        <section aria-label="Resumo da inscrição" class="painel-retaguarda p-5 @xl:p-6">
            <dl class="grid gap-x-6 gap-y-4 text-[0.9375rem] @xl:grid-cols-2 @3xl:grid-cols-3">
                <div>
                    <dt :class="classeTermo">Número</dt>
                    <dd class="font-semibold tabular-nums">{{ inscricao.numero }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Edital</dt>
                    <dd class="font-semibold break-words">{{ inscricao.edital }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Chamada</dt>
                    <dd class="font-semibold break-words">{{ inscricao.chamada ?? 'Sem chamada' }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Proponente</dt>
                    <dd class="font-semibold break-words">{{ inscricao.proponente }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">{{ inscricao.cadastro === 'Pessoa física' ? 'CPF' : 'CNPJ' }}</dt>
                    <dd class="font-semibold tabular-nums">{{ inscricao.documento }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Finalizada em</dt>
                    <dd class="font-semibold tabular-nums">{{ inscricao.finalizadaEm }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Período de homologação</dt>
                    <dd class="font-semibold tabular-nums">{{ periodoDeHomologacao }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Situação</dt>
                    <dd class="font-semibold">{{ inscricao.situacaoRotulo }}</dd>
                </div>
                <div v-if="inscricao.prazoDoRecurso">
                    <dt :class="classeTermo">Prazo do recurso</dt>
                    <dd class="font-semibold tabular-nums">até {{ inscricao.prazoDoRecurso }}</dd>
                </div>
            </dl>
        </section>

        <SecaoCadastro id="secao-escolhas" :numero="1" titulo="Escolhas e perfil">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.escolhas" />
                </svg>
            </template>

            <dl class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <dt class="text-[0.9375rem] font-semibold">Cadastro</dt>
                    <dd :class="classeLeitura">{{ inscricao.cadastro }}</dd>
                </div>
                <div class="flex flex-col gap-1.5">
                    <dt class="text-[0.9375rem] font-semibold">Categoria</dt>
                    <dd :class="[classeLeitura, inscricao.categoria === null ? 'text-tinta-suave' : 'break-words']">{{ inscricao.categoria ?? 'Sem categoria' }}</dd>
                </div>
                <div class="flex flex-col gap-1.5 md:col-span-2">
                    <dt class="text-[0.9375rem] font-semibold">Cotas</dt>
                    <dd :class="[classeLeitura, inscricao.cotas.length === 0 ? 'text-tinta-suave' : 'break-words']">
                        {{ inscricao.cotas.length > 0 ? inscricao.cotas.join(', ') : 'Sem cota' }}
                    </dd>
                </div>
                <div v-for="linha in perfil" :key="linha.rotulo" class="flex flex-col gap-1.5">
                    <dt class="text-[0.9375rem] font-semibold">{{ linha.rotulo }}</dt>
                    <dd :class="[classeLeitura, linha.valor === null ? 'text-tinta-suave' : 'break-words']">{{ linha.valor ?? 'Não informado' }}</dd>
                </div>
            </dl>
        </SecaoCadastro>

        <SecaoCadastro id="secao-documentos" :numero="2" titulo="Documentos">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.documentos" />
                </svg>
            </template>

            <p v-if="documentos.length === 0" class="text-tinta-suave">Nenhum documento é exigido para as escolhas desta inscrição.</p>
            <ul v-else class="flex flex-col gap-4">
                <li v-for="documento in documentos" :key="documento.id" class="flex flex-col gap-4 rounded-[16px] border border-hairline p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="text-[1.0625rem] font-bold break-words">{{ documento.nome }}</h3>
                            <p class="text-[0.8125rem] text-tinta-suave">{{ documento.etapaRotulo }}{{ documento.obrigatorio ? ' · obrigatório' : ' · opcional' }}</p>
                        </div>
                        <span v-if="documento.ia" class="selo" :class="classeDoParecerDaIa(documento.ia.situacao)">IA: {{ documento.ia.rotulo }}</span>
                    </div>

                    <div v-if="documento.enviado" class="flex items-center gap-1 rounded-[12px] bg-parchment px-3 py-2">
                        <span class="min-w-0 flex-1 truncate text-[0.9375rem]" :title="documento.enviado.nome">{{ documento.enviado.nome }}</span>
                        <a :href="documento.enviado.url" target="_blank" rel="noopener" class="acao-icone" data-dica="Abrir em nova aba">
                            <IconeAcao nome="abrir" />
                            <span class="sr-only">Abrir em nova aba: {{ documento.nome }}</span>
                        </a>
                    </div>
                    <p v-else class="text-[0.9375rem] text-tinta-suave">Não enviado</p>

                    <p v-if="documento.ia?.motivo" class="text-[0.9375rem] leading-[1.6]">
                        <span class="font-semibold">Parecer da IA:</span> {{ documento.ia.motivo }}
                    </p>

                    <div class="border-t border-hairline pt-4">
                        <MarcacaoDoItem :inscricao-id="inscricao.id" tipo="documento" :item="documento.id" :marcacao="documento.marcacao" :desabilitado="!podeConferir" />
                    </div>
                </li>
            </ul>
        </SecaoCadastro>

        <SecaoCadastro id="secao-questionario" :numero="3" titulo="Questionário">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.questionario" />
                </svg>
            </template>

            <p v-if="secoesConferidas.length === 0" class="text-tinta-suave">O edital não tem perguntas para esta inscrição.</p>
            <div v-else class="flex flex-col gap-8">
                <section v-for="secao in secoesConferidas" :key="secao.id" :aria-labelledby="`questionario-secao-${secao.id}`" class="flex flex-col gap-4">
                    <h3 :id="`questionario-secao-${secao.id}`" class="text-[1.0625rem] font-bold">{{ secao.titulo }}</h3>
                    <ul class="flex flex-col gap-4">
                        <li v-for="pergunta in secao.perguntas" :key="pergunta.id" class="flex flex-col gap-3 rounded-[16px] border border-hairline p-5">
                            <dl class="flex flex-col gap-1.5">
                                <dt class="font-semibold break-words">{{ pergunta.enunciado }}</dt>
                                <dd v-if="textoDaResposta(pergunta, respostas[pergunta.id])" class="break-words whitespace-pre-line">
                                    {{ textoDaResposta(pergunta, respostas[pergunta.id]) }}
                                </dd>
                                <dd v-else class="text-tinta-suave italic">Sem resposta</dd>
                            </dl>
                            <div class="border-t border-hairline pt-4">
                                <MarcacaoDoItem
                                    :inscricao-id="inscricao.id"
                                    tipo="pergunta"
                                    :item="pergunta.id"
                                    :marcacao="marcacoesDasPerguntas[pergunta.id] ?? null"
                                    :desabilitado="!podeConferir"
                                />
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </SecaoCadastro>

        <SecaoCadastro id="secao-decisao" :numero="4" titulo="Decisão">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.decisao" />
                </svg>
            </template>

            <div v-if="inscricao.homologacao === null" class="flex flex-col gap-4">
                <p class="text-[0.9375rem] font-semibold tabular-nums">{{ resumoDoAndamento }}</p>
                <DecisaoDaHomologacao :inscricao-id="inscricao.id" :pode-conferir="podeConferir" :pode-homologar="podeHomologar" :motivo-sugerido="motivoSugerido" />
            </div>

            <dl v-else class="flex flex-col gap-4">
                <div>
                    <dt :class="classeTermo">Situação</dt>
                    <dd class="font-semibold">{{ inscricao.situacaoRotulo }}</dd>
                </div>
                <div v-if="inscricao.motivo">
                    <dt :class="classeTermo">Motivo</dt>
                    <dd class="break-words whitespace-pre-line">{{ inscricao.motivo }}</dd>
                </div>
                <div>
                    <dt :class="classeTermo">Decidida</dt>
                    <dd>
                        <template v-if="inscricao.decididaPor">por {{ inscricao.decididaPor }}</template>
                        <template v-if="inscricao.decididaEm"> em {{ inscricao.decididaEm }}</template>
                    </dd>
                </div>
                <div v-if="inscricao.prazoDoRecurso">
                    <dt :class="classeTermo">Prazo do recurso</dt>
                    <dd class="tabular-nums">até {{ inscricao.prazoDoRecurso }}</dd>
                </div>
            </dl>
        </SecaoCadastro>

        <SecaoCadastro v-if="recurso" id="secao-recurso" :numero="5" titulo="Recurso">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.recurso" />
                </svg>
            </template>

            <JulgamentoDoRecurso :inscricao-id="inscricao.id" :recurso="recurso" :pode-julgar="podeJulgar" />
        </SecaoCadastro>

        <div>
            <Link :href="BASE" class="botao-secundario">Voltar para a lista</Link>
        </div>
    </PaginaRetaguarda>
</template>
