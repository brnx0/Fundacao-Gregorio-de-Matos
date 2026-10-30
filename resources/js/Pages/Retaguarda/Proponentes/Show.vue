<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaProponenteShowProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

defineProps<RetaguardaProponenteShowProps>();

// Ícone de cada bloco do cadastro (os mesmos blocos do formulário do portal).
const ICONES: Record<string, string> = {
    'dados-pessoais': 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
    documentos: 'M3 6h18v12H3zM7 10h4M7 14h6M15 10h2M15 14h2',
    profissional: 'M4 8h16v11H4zM9 8V5h6v3M4 13h16',
    contato: 'M4 5h16v14H4zM4 6l8 7 8-7',
    identidade: 'M12 20s-7-4.4-7-9.5A4 4 0 0 1 12 8a4 4 0 0 1 7 2.5C19 15.6 12 20 12 20z',
    endereco: 'M12 21s-6-5.6-6-11a6 6 0 0 1 12 0c0 5.4-6 11-6 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
};

const classeLeitura = 'flex min-h-12 items-center rounded-[12px] border border-hairline bg-nevoa px-4 text-base text-home-tinta';
</script>

<template>
    <PaginaRetaguarda largura="estreita" :titulo="proponente.nome" :trilha="[{ texto: 'Detalhes' }]">
        <Head :title="`${proponente.nome} · Proponentes · Retaguarda`" />

        <template #detalhe>
            <span v-for="tipo in proponente.tipos" :key="tipo" class="selo-faixa">{{ tipo }}</span>
            <span v-if="!proponente.finalizado" class="selo-faixa">
                <span aria-hidden="true" class="size-2 rounded-full bg-situacao-em-breve" />
                Cadastro em andamento
            </span>
        </template>

        <template #acoes>
            <dl class="vidro grid grid-cols-2 gap-x-6 rounded-[18px] px-5 py-3 text-[0.8125rem]">
                <div>
                    <dt class="text-white/70">Cadastrado em</dt>
                    <dd class="font-semibold tabular-nums">{{ proponente.cadastradoEm ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-white/70">Último acesso</dt>
                    <dd class="font-semibold tabular-nums">{{ proponente.ultimoAcesso ?? '—' }}</dd>
                </div>
            </dl>
        </template>

        <SecaoCadastro v-for="(secao, indice) in secoes" :id="`secao-${secao.id}`" :key="secao.id" :numero="indice + 1" :titulo="secao.titulo">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ICONES[secao.id]" />
                </svg>
            </template>

            <dl class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                <div v-for="campo in secao.campos" :key="campo.rotulo" class="flex flex-col gap-1.5">
                    <dt class="text-[0.9375rem] font-semibold">{{ campo.rotulo }}</dt>
                    <dd :class="[classeLeitura, campo.valor === null ? 'text-tinta-suave' : 'break-words']">{{ campo.valor ?? 'Não informado' }}</dd>
                </div>
            </dl>
        </SecaoCadastro>

        <SecaoCadastro id="secao-anexos" :numero="secoes.length + 1" titulo="Anexos">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m21 11-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7" />
                </svg>
            </template>

            <ul class="grid gap-4 md:grid-cols-2">
                <li v-for="anexo in anexos" :key="anexo.campo" class="flex items-center gap-4 rounded-[16px] border border-hairline p-4">
                    <span aria-hidden="true" class="inline-flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-nevoa text-acao">
                        <img v-if="anexo.imagem && anexo.url" :src="anexo.url" alt="" class="size-full object-cover" loading="lazy" />
                        <svg v-else viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M7 3h7l5 5v13H7zM14 3v5h5" />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ anexo.rotulo }}</p>
                        <p v-if="!anexo.enviado || !anexo.url" class="text-[0.9375rem] text-tinta-suave">Não enviado</p>
                        <div v-else class="-ml-3 flex">
                            <a :href="anexo.url" target="_blank" rel="noopener" class="acao-icone" data-dica="Abrir em nova aba">
                                <IconeAcao nome="abrir" />
                                <span class="sr-only">Abrir em nova aba: {{ anexo.rotulo }}</span>
                            </a>
                            <a :href="anexo.url" :download="anexo.nome ?? ''" class="acao-icone" data-dica="Baixar">
                                <IconeAcao nome="baixar" />
                                <span class="sr-only">Baixar {{ anexo.rotulo }}</span>
                            </a>
                        </div>
                    </div>
                </li>
            </ul>
        </SecaoCadastro>

        <SecaoCadastro id="secao-organizacoes" :numero="secoes.length + 2" titulo="Pessoas Jurídicas">
            <template #icone>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M15 10h.01M9 14h.01M15 14h.01M10 21v-3h4v3" />
                </svg>
            </template>

            <p v-if="!organizacoes.length" class="text-tinta-suave">Nenhuma Pessoa Jurídica vinculada a este CPF.</p>
            <ul v-else class="flex flex-col gap-4">
                <li v-for="organizacao in organizacoes" :key="organizacao.id" class="rounded-[16px] border border-hairline p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="text-[1.0625rem] font-bold break-words">{{ organizacao.razaoSocial }}</h3>
                            <p v-if="organizacao.nomeFantasia" class="text-[0.9375rem] text-tinta-suave">{{ organizacao.nomeFantasia }}</p>
                        </div>
                        <span v-if="organizacao.responsavel" class="selo selo-etiqueta">Responsável</span>
                    </div>
                    <dl class="mt-4 grid gap-x-6 gap-y-3 text-[0.9375rem] sm:grid-cols-3">
                        <div>
                            <dt class="text-tinta-suave">CNPJ</dt>
                            <dd class="font-semibold tabular-nums">{{ organizacao.cnpj }}</dd>
                        </div>
                        <div>
                            <dt class="text-tinta-suave">Porte</dt>
                            <dd class="font-semibold">{{ organizacao.porte ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-tinta-suave">Cidade</dt>
                            <dd class="font-semibold">{{ organizacao.cidade ?? '—' }}</dd>
                        </div>
                    </dl>
                    <div v-if="organizacao.representantes.length" class="mt-4 border-t border-hairline pt-4">
                        <h4 class="text-[0.9375rem] font-semibold">Representantes legais</h4>
                        <ul class="mt-2 flex flex-col gap-1 text-[0.9375rem]">
                            <li v-for="(representante, indice) in organizacao.representantes" :key="indice">
                                {{ representante.nome }}<span v-if="representante.cargo" class="text-tinta-suave"> · {{ representante.cargo }}</span>
                                <span class="text-tinta-suave tabular-nums"> · {{ representante.cpf }}</span>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </SecaoCadastro>
    </PaginaRetaguarda>
</template>
