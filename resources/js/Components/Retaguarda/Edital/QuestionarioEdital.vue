<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import CampoCadastro from '../../CampoCadastro.vue';
import ListaEditavel from './ListaEditavel.vue';
import PerguntaDoQuestionario from './PerguntaDoQuestionario.vue';
import QuadroImportacao from './QuadroImportacao.vue';
import VincularEmLote from './VincularEmLote.vue';
import { classeCampo, classeDica, classeTexto, novaChave } from '../listaEditavel';
import { TIPO_PADRAO, perguntasEmOrdem, semVinculo } from '../questionario';
import type { CategoriaDoQuestionario, ImportacaoDoEdital, OpcaoFormulario, PerguntaEditavel, SecaoQuestionarioEditavel, VinculoEditavel } from '../../../types';

/**
 * Aba Questionário do formulário do edital: seções com perguntas, vínculo (um a um e em lote) e condições.
 * Com inscrição finalizada no edital o questionário fica travado: só leitura, com o motivo.
 */
const props = defineProps<{
    /** Erros do useForm; os do questionário vêm como "questionario" e "questionario.secoes.S.perguntas.P.campo". */
    erros: Partial<Record<string, string>>;
    /** Categorias gravadas do edital, na ordem em que estão. */
    categorias: CategoriaDoQuestionario[];
    tiposPergunta: OpcaoFormulario[];
    travado: boolean;
    avisoTravado: string;
    /** Condições removidas pelo formulário (origem apagada, opção tirada), para anunciar; `id` único por aviso. */
    avisos: { id: number; texto: string }[];
    /** Quadro "Importado pela IA" da última importação, enquanto não foi conferida. */
    quadro: ImportacaoDoEdital | null;
    /** POST que marca a importação como conferida; null enquanto o edital não foi salvo. */
    urlConferida: string | null;
}>();

const emit = defineEmits<{ limparErros: [campos: string[]] }>();

const secoes = defineModel<SecaoQuestionarioEditavel[]>({ required: true });

const PREFIXO = 'questionario.secoes';

const anuncio = ref<string>('');

const todas = computed<PerguntaEditavel[]>(() => perguntasEmOrdem(secoes.value));
const quantasSemVinculo = computed<number>(() => todas.value.filter(semVinculo).length);

// O aviso da trava já aparece no topo; outro erro geral (ex.: pergunta sem vínculo em edital publicado) aparece aqui.
const erroGeral = computed<string | undefined>(() => {
    const erro = props.erros.questionario;

    return props.travado && erro === props.avisoTravado ? undefined : erro;
});

const rotuloDoTipo = (tipo: string): string => props.tiposPergunta.find((opcao) => opcao.valor === tipo)?.rotulo ?? tipo;

const plural = (quantidade: number, singular: string, varias: string): string => `${quantidade} ${quantidade === 1 ? singular : varias}`;

const resumoDaSecao = (secao: SecaoQuestionarioEditavel): string => {
    const sem = secao.perguntas.filter(semVinculo).length;

    return [plural(secao.perguntas.length, 'pergunta', 'perguntas'), sem > 0 ? plural(sem, 'sem vínculo', 'sem vínculo') : null].filter(Boolean).join(' · ');
};

const resumoDaPergunta = (pergunta: PerguntaEditavel): string => [rotuloDoTipo(pergunta.tipo), pergunta.obrigatoria ? 'Obrigatória' : null].filter(Boolean).join(' · ');

const novaSecao = (): SecaoQuestionarioEditavel => ({ chave: novaChave(), id: null, titulo: '', descricao: '', perguntas: [] });

const novaPergunta = (): PerguntaEditavel => ({
    chave: novaChave(),
    id: null,
    enunciado: '',
    ajuda: '',
    tipo: props.tiposPergunta.some((tipo) => tipo.valor === TIPO_PADRAO) ? TIPO_PADRAO : (props.tiposPergunta[0]?.valor ?? TIPO_PADRAO),
    obrigatoria: true,
    limite_caracteres: null,
    opcoes: [],
    todas_categorias: true,
    categorias: [],
    condicao_pergunta: null,
    condicao_opcoes: [],
});

const errosDoQuestionario = (filtro: (chave: string) => boolean): string[] => Object.keys(props.erros).filter((chave) => chave.startsWith(`${PREFIXO}.`) && filtro(chave));

/** Foca o primeiro dos elementos que estiver visível (o cartão da pergunta fica escondido com a seção recolhida). */
const focarVisivel = async (...ids: string[]): Promise<void> => {
    await nextTick();
    setTimeout(() => {
        const alvo = ids.map((id) => document.getElementById(id)).find((elemento) => elemento !== null && elemento.getClientRects().length > 0);
        alvo?.focus();
    }, 0);
};

// Os índices das duas seções mudam: os erros das perguntas deixam de apontar para o cartão certo.
const moverPergunta = (origem: SecaoQuestionarioEditavel, indice: number, chaveDoDestino: string): void => {
    const posicaoDoDestino = secoes.value.findIndex((secao) => secao.chave === chaveDoDestino);
    const destino = secoes.value[posicaoDoDestino];
    if (!destino || destino === origem) return;

    const [pergunta] = origem.perguntas.splice(indice, 1);
    if (!pergunta) return;
    destino.perguntas.push(pergunta);

    emit('limparErros', errosDoQuestionario((chave) => chave.includes('.perguntas')));
    anuncio.value = `Pergunta movida para o fim da seção ${posicaoDoDestino + 1}${destino.titulo.trim() ? ` (${destino.titulo.trim()})` : ''}.`;
    void focarVisivel(`pergunta-${destino.chave}-${pergunta.chave}-cartao`, `secao-questionario-${destino.chave}-cartao`);
};

// O quadro já está na aba do questionário: "Ir para a aba" leva o foco à primeira seção.
const irParaAsSecoes = (): void => {
    const primeira = secoes.value[0];
    if (primeira) void focarVisivel(`secao-questionario-${primeira.chave}-cartao`);
};

const aplicarEmLote = (chaves: string[], vinculo: VinculoEditavel): void => {
    const escolhidas = new Set(chaves);
    for (const pergunta of todas.value) {
        if (!escolhidas.has(pergunta.chave)) continue;
        pergunta.todas_categorias = vinculo.todas_categorias;
        pergunta.categorias = [...vinculo.categorias];
    }

    emit('limparErros', errosDoQuestionario((chave) => /\.perguntas\.\d+\.(todas_categorias|categorias)(\.\d+)?$/.test(chave)));
    anuncio.value = `Vínculo aplicado a ${plural(chaves.length, 'pergunta', 'perguntas')}. Salve o questionário para gravar.`;
};
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-if="travado" role="note" class="flex items-start gap-3 rounded-[16px] border border-aviso-texto/30 bg-aviso-fundo p-4 text-aviso-texto">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="11" width="14" height="10" rx="2" />
                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
            </svg>
            <p class="text-[0.9375rem] leading-[1.6] font-semibold">{{ avisoTravado }}</p>
        </div>

        <QuadroImportacao v-if="quadro && urlConferida" :importacao="quadro" :url-conferida="urlConferida" :abas="['questionario']" destino="Ir para as perguntas" @ir-para-aba="irParaAsSecoes" />

        <p v-if="erroGeral" id="questionario-erro" role="alert" class="rounded-[12px] bg-erro-fundo px-4 py-3 text-[0.9375rem] font-semibold text-invalido-texto">{{ erroGeral }}</p>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
            <p class="text-[0.9375rem] leading-[1.6] text-tinta-suave">
                Perguntas que o proponente responde na inscrição.
                <template v-if="quantasSemVinculo > 0">
                    <span class="font-semibold text-aviso-texto">{{ plural(quantasSemVinculo, 'pergunta está sem vínculo', 'perguntas estão sem vínculo') }}</span>: o edital
                    não publica até vincular.
                </template>
            </p>
            <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                <slot name="importar" />
                <VincularEmLote
                    :secoes="secoes"
                    :categorias="categorias"
                    :desabilitado="travado || todas.length === 0"
                    @aplicar="aplicarEmLote"
                />
            </div>
        </div>

        <!-- A região fica sempre no DOM (o leitor de tela só anuncia o que muda numa região que já existe); vazia, não ocupa espaço. -->
        <div aria-live="polite" class="flex flex-col gap-2" :class="{ '-mt-5': avisos.length === 0 && !anuncio }">
            <p v-for="aviso in avisos" :key="aviso.id" class="rounded-[12px] bg-aviso-fundo px-4 py-3 text-[0.9375rem] font-semibold text-aviso-texto">{{ aviso.texto }}</p>
            <p v-if="anuncio" class="text-[0.9375rem] text-tinta-suave">{{ anuncio }}</p>
        </div>

        <!-- Travado: sem adicionar, mover e remover, e os campos desabilitados; seções e perguntas continuam abrindo para leitura. -->
        <div class="min-w-0">
            <ListaEditavel
                v-model="secoes"
                id="secao-questionario"
                :prefixo="PREFIXO"
                :erros="erros"
                singular="seção"
                plural="seções"
                feminino
                vazio="Nenhuma seção. Crie uma seção para cadastrar as perguntas."
                :novo="novaSecao"
                campo-inicial="titulo"
                :nome="(secao) => secao.titulo"
                :resumo="resumoDaSecao"
                :somente-leitura="travado"
                @limpar-erros="emit('limparErros', $event)"
            >
                <template #default="{ item: secao, indice: indiceDaSecao, idCampo, erro, limpar }">
                    <fieldset :disabled="travado" class="grid min-w-0 gap-y-5">
                        <CampoCadastro :id="idCampo('titulo')" rotulo="Título" obrigatorio :erro="erro('titulo')" v-slot="{ descricao, invalido }">
                            <input
                                :id="idCampo('titulo')"
                                v-model="secao.titulo"
                                type="text"
                                maxlength="255"
                                required
                                placeholder="Ex.: Dados do projeto"
                                :class="classeCampo"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="limpar('titulo')"
                            />
                        </CampoCadastro>

                        <CampoCadastro :id="idCampo('descricao')" rotulo="Descrição" ajuda="Texto curto mostrado no início da seção." :erro="erro('descricao')" v-slot="{ descricao, invalido }">
                            <textarea
                                :id="idCampo('descricao')"
                                v-model="secao.descricao"
                                rows="2"
                                maxlength="2000"
                                :class="classeTexto"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="limpar('descricao')"
                            />
                        </CampoCadastro>
                    </fieldset>

                    <div class="flex min-w-0 flex-col gap-3">
                        <p class="text-[0.9375rem] font-semibold">Perguntas</p>
                        <ListaEditavel
                            v-model="secao.perguntas"
                            :id="`pergunta-${secao.chave}`"
                            :prefixo="`${PREFIXO}.${indiceDaSecao}.perguntas`"
                            :erros="erros"
                            singular="pergunta"
                            plural="perguntas"
                            feminino
                            :nivel="4"
                            vazio="Nenhuma pergunta nesta seção."
                            :novo="novaPergunta"
                            campo-inicial="enunciado"
                            :nome="(pergunta) => pergunta.enunciado"
                            :resumo="resumoDaPergunta"
                            :somente-leitura="travado"
                            @limpar-erros="emit('limparErros', $event)"
                        >
                            <template #selo="{ item: pergunta }">
                                <span
                                    v-if="semVinculo(pergunta)"
                                    class="ml-auto shrink-0 rounded-pill border border-aviso-texto/40 bg-aviso-fundo px-2.5 py-0.5 text-[0.8125rem] font-bold text-aviso-texto"
                                >
                                    Vincule às categorias
                                </span>
                            </template>
                            <template #default="{ item: pergunta, indice, idCampo: idDaPergunta, erro: erroDaPergunta, erroDaLista, limpar: limparDaPergunta, limparLista }">
                                <PerguntaDoQuestionario
                                    :model-value="pergunta"
                                    :secoes="secoes"
                                    :secao="secao.chave"
                                    :categorias="categorias"
                                    :tipos-pergunta="tiposPergunta"
                                    :id-campo="idDaPergunta"
                                    :erro="erroDaPergunta"
                                    :erro-da-lista="erroDaLista"
                                    :limpar="limparDaPergunta"
                                    :limpar-lista="limparLista"
                                    :desabilitado="travado"
                                    @mover="moverPergunta(secao, indice, $event)"
                                />
                            </template>
                        </ListaEditavel>
                    </div>
                </template>
            </ListaEditavel>
        </div>

        <p v-if="!travado && secoes.length > 0" :class="classeDica">Remover, mover ou reordenar só vale depois de salvar o questionário.</p>
    </div>
</template>
