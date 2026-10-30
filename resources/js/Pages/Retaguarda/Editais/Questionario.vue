<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import ImportarQuestionario from '../../../Components/Retaguarda/Edital/ImportarQuestionario.vue';
import QuestionarioEdital from '../../../Components/Retaguarda/Edital/QuestionarioEdital.vue';
import IconeAcao from '../../../Components/Retaguarda/IconeAcao.vue';
import { novaChave } from '../../../Components/Retaguarda/listaEditavel';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import { perguntasEmOrdem, questionarioParaEnvio, secoesEditaveis, vigiarCondicoes } from '../../../Components/Retaguarda/questionario';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { CategoriaDoQuestionario, RetaguardaEditalQuestionarioProps } from '../../../types';

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaEditalQuestionarioProps>();

// Caminhos das rotas retaguarda.editais.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/editais';
const URL_EDITAL = `${BASE}/${props.edital.slug}`;

// Categorias gravadas, na ordem: o servidor fala em posições; a tela, nas chaves dos cartões.
const categorias: CategoriaDoQuestionario[] = props.edital.categorias.map((categoria) => ({
    chave: novaChave(),
    nome: categoria.nome,
}));

const form = useForm({ secoes: secoesEditaveis(props.edital.questionario, categorias) });

// Os erros chegam como "questionario" e "questionario.secoes.S.perguntas.P.campo": chaves que o tipo do useForm não prevê.
const erros = computed<Partial<Record<string, string>>>(() => form.errors as Partial<Record<string, string>>);

const limparErros = (campos: string[]): void => {
    if (campos.length === 0) return;
    form.clearErrors(...(campos as Parameters<typeof form.clearErrors>));
};

const avisos = vigiarCondicoes(() => form.secoes);

const perguntas = computed<number>(() => perguntasEmOrdem(form.secoes).length);
const tituloDoEdital = props.edital.titulo || 'Edital sem título';

// A versão é a da importação que a tela abriu: se outra concluiu depois, o servidor recusa em vez de apagar a importada.
const salvar = (): void => {
    avisos.value = [];
    form.transform(({ secoes }) => ({
        questionario_versao: props.questionarioVersao,
        questionario: questionarioParaEnvio(secoes, categorias),
    })).put(`${URL_EDITAL}/questionario`, {
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-questionario'),
    });
};
</script>

<template>
    <PaginaRetaguarda largura="estreita" :titulo="tituloDoEdital" :trilha="[{ texto: 'Questionário' }]">
        <Head :title="`Questionário · ${tituloDoEdital} · Retaguarda`" />

        <template #detalhe>
            <span class="selo-faixa">
                <span aria-hidden="true" class="size-2 rounded-full" :class="perguntas > 0 ? 'bg-situacao-aberto' : 'bg-situacao-em-breve'" />
                {{ perguntas === 0 ? 'Sem perguntas' : perguntas === 1 ? '1 pergunta' : `${perguntas} perguntas` }}
            </span>
            <span v-if="edital.publicado" class="selo-faixa">Edital publicado</span>
        </template>

        <template #acoes>
            <Link
                :href="`${URL_EDITAL}/edit`"
                class="vidro vidro-pilula pressionavel inline-flex min-h-11 items-center justify-center gap-2 rounded-pill px-5 text-[0.8125rem] font-bold tracking-[0.1em] text-white uppercase"
            >
                <IconeAcao nome="editar" />
                Editar edital
            </Link>
        </template>

        <form id="formulario-questionario" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <section aria-label="Questionário" class="painel-retaguarda p-5 @xl:p-8">
                <QuestionarioEdital
                    v-model="form.secoes"
                    :erros="erros"
                    :categorias="categorias"
                    :tipos-pergunta="tiposPergunta"
                    :travado="questionarioTravado"
                    :aviso-travado="avisoQuestionarioTravado"
                    :avisos="avisos"
                    :quadro="quadroQuestionario"
                    :url-conferida="`${URL_EDITAL}/questionario/importacao/conferida`"
                    @limpar-erros="limparErros"
                >
                    <template #importar>
                        <ImportarQuestionario
                            :edital-slug="edital.slug"
                            :tem-categoria-salva="edital.categorias.length > 0"
                            :tem-secoes="form.secoes.length > 0"
                            :travado="questionarioTravado"
                            :aviso-travado="avisoQuestionarioTravado"
                            :alteracoes-nao-salvas="form.isDirty"
                            :configurada="importacaoQuestionarioConfigurada"
                            :tamanho-maximo-mb="tamanhoMaximoArquivoMb"
                            :importacao="importacaoQuestionario"
                        />
                    </template>
                </QuestionarioEdital>
            </section>

            <!-- Travado, não há o que salvar: só volta para a lista. -->
            <div v-if="questionarioTravado">
                <Link :href="BASE" class="botao-secundario">Voltar para a lista</Link>
            </div>
            <BarraAcoes v-else :cancelar="BASE" :salvar="form.processing ? 'Salvando…' : 'Salvar questionário'" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
