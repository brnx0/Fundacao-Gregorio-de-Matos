<script setup lang="ts">
import { computed } from 'vue';
import { textoDaResposta } from '../Inscricao/questionario';
import IconeAcao from '../Retaguarda/IconeAcao.vue';
import SecaoCadastro from '../SecaoCadastro.vue';
import type { InscricaoCompletaProps } from '../../types';

// A inscrição só para leitura, nas mesmas seções da conferência da homologação (Retaguarda/Homologacao/Show), sem o
// parecer da IA nem as marcações do gestor. Os arquivos abrem pela rota do avaliador.
const props = defineProps<InscricaoCompletaProps>();

// Só as perguntas visíveis pela condição; seção sem nenhuma não aparece.
const secoesVisiveis = computed(() => {
    const visiveis = new Set(props.perguntasVisiveis);
    return props.secoes
        .map((secao) => ({ ...secao, perguntas: secao.perguntas.filter((pergunta) => visiveis.has(pergunta.id)) }))
        .filter((secao) => secao.perguntas.length > 0);
});

const ICONES = {
    escolhas: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
    documentos: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    questionario:
        'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM12 11h4M12 16h4M8 11h.01M8 16h.01',
};

const classeTermo = 'text-[0.8125rem] font-semibold text-tinta-suave';
const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
</script>

<template>
    <div class="flex flex-col gap-6">
        <section aria-label="Resumo da inscrição" class="rounded-[24px] border border-hairline bg-superficie p-6 shadow-[0_24px_60px_-34px_rgba(6,36,74,0.45)] md:p-8">
            <dl class="grid gap-x-6 gap-y-4 text-[0.9375rem] sm:grid-cols-2 lg:grid-cols-3">
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
                    <div class="min-w-0">
                        <h3 class="text-[1.0625rem] font-bold break-words">{{ documento.nome }}</h3>
                        <p class="text-[0.8125rem] text-tinta-suave">{{ documento.etapaRotulo }}{{ documento.obrigatorio ? ' · obrigatório' : ' · opcional' }}</p>
                    </div>

                    <div v-if="documento.enviado" class="flex items-center gap-1 rounded-[12px] bg-parchment px-3 py-2">
                        <span class="min-w-0 flex-1 truncate text-[0.9375rem]" :title="documento.enviado.nome">{{ documento.enviado.nome }}</span>
                        <a :href="documento.enviado.url" target="_blank" rel="noopener" class="acao-icone" data-dica="Abrir em nova aba">
                            <IconeAcao nome="abrir" />
                            <span class="sr-only">Abrir em nova aba: {{ documento.nome }}</span>
                        </a>
                    </div>
                    <p v-else class="text-[0.9375rem] text-tinta-suave">Não enviado</p>
                </li>
            </ul>
        </SecaoCadastro>

        <SecaoCadastro id="secao-questionario" :numero="3" titulo="Questionário">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES.questionario" />
                </svg>
            </template>

            <p v-if="secoesVisiveis.length === 0" class="text-tinta-suave">O edital não tem perguntas para esta inscrição.</p>
            <div v-else class="flex flex-col gap-8">
                <section v-for="secao in secoesVisiveis" :key="secao.id" :aria-labelledby="`questionario-secao-${secao.id}`" class="flex flex-col gap-4">
                    <h3 :id="`questionario-secao-${secao.id}`" class="text-[1.0625rem] font-bold">{{ secao.titulo }}</h3>
                    <ul class="flex flex-col gap-4">
                        <li v-for="pergunta in secao.perguntas" :key="pergunta.id" class="rounded-[16px] border border-hairline p-5">
                            <dl class="flex flex-col gap-1.5">
                                <dt class="font-semibold break-words">{{ pergunta.enunciado }}</dt>
                                <dd v-if="textoDaResposta(pergunta, respostas[pergunta.id])" class="break-words whitespace-pre-line">
                                    {{ textoDaResposta(pergunta, respostas[pergunta.id]) }}
                                </dd>
                                <dd v-else class="text-tinta-suave italic">Sem resposta</dd>
                            </dl>
                        </li>
                    </ul>
                </section>
            </div>
        </SecaoCadastro>
    </div>
</template>
