<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import CampoAnexoCadastro from '../../Components/Cadastro/CampoAnexoCadastro.vue';
import { useSituacaoDosAnexos } from '../../Components/Cadastro/useSituacaoDosAnexos';
import EtapasCadastro from '../../Components/EtapasCadastro.vue';
import FaixaPortal from '../../Components/FaixaPortal.vue';
import SaudacaoCadastro from '../../Components/SaudacaoCadastro.vue';
import SecaoCadastro from '../../Components/SecaoCadastro.vue';
import PortalProponenteLayout from '../../Layouts/PortalProponenteLayout.vue';
import type { AnexoDoCadastro, ArquivoCadastro, CadastroAnexosProps } from '../../types';

defineOptions({ layout: PortalProponenteLayout });

const props = defineProps<CadastroAnexosProps>();

const ERRO_SEM_ARQUIVO = 'Envie este arquivo para continuar.';
const ID_PENDENCIAS = 'anexos-pendencias';

// Com o tipo de cadastro escolhido, esta tela é uma aba do cadastro: sem "Continuar", só para trocar os arquivos.
const finalizado = computed(() => !!usePage().props.auth.user?.tiposCadastro.length);

// O Inertia faz uma requisição comum por vez: a nova cancela a que ainda está em andamento (um envio de arquivo, por exemplo)
// sem avisar. Enquanto houver uma, os controles que disparam outra ficam desabilitados. A consulta periódica usa
// fetch e as requisições assíncronas não interrompem ninguém, então nenhuma das duas conta.
const requisicoesEmAndamento = ref<number>(0);
const ocupado = computed<boolean>(() => requisicoesEmAndamento.value > 0);
const pararDeOuvirInicio = router.on('start', (evento) => {
    if (!evento.detail.visit.async) {
        requisicoesEmAndamento.value += 1;
    }
});
const pararDeOuvirFim = router.on('finish', (evento) => {
    if (!evento.detail.visit.async) {
        requisicoesEmAndamento.value = Math.max(0, requisicoesEmAndamento.value - 1);
    }
});
onBeforeUnmount(() => {
    pararDeOuvirInicio();
    pararDeOuvirFim();
});

const { situacoes, pendencias, podeContinuar } = useSituacaoDosAnexos(props);

// Uma lista só, com os obrigatórios primeiro; dentro de cada grupo, a ordem do servidor (App\Enums\AnexoCadastro).
const emOrdem = computed<AnexoDoCadastro[]>(() => [
    ...props.anexos.filter((anexo) => anexo.obrigatorio),
    ...props.anexos.filter((anexo) => !anexo.obrigatorio),
]);
const algumConferidoPelaIa = computed<boolean>(() => props.anexos.some((anexo) => anexo.conferidoPelaIa));

// "Continuar" mostra o que falta; o erro de cada obrigatório some quando o arquivo chega. Quem volta para cá com erro (do
// Tipo de Cadastro, por exemplo) já vê a lista.
const tentouContinuar = ref<boolean>(Boolean(usePage().props.flash.erro) && props.pendencias.length > 0);
const continuando = ref<boolean>(false);

const pendente = (campo: ArquivoCadastro): boolean => pendencias.value.some((pendencia) => pendencia.campo === campo);
const erroDoAnexo = (anexo: AnexoDoCadastro): string | undefined =>
    tentouContinuar.value && anexo.obrigatorio && !situacoes.value[anexo.campo] ? ERRO_SEM_ARQUIVO : undefined;

const focarPendencias = (): void => {
    void nextTick(() => document.getElementById(ID_PENDENCIAS)?.focus());
};

// O link da pendência leva ao botão principal do anexo (que rola para a vista ao receber o foco) ou, sem ele, à linha.
const irParaAnexo = (campo: ArquivoCadastro): void => {
    (document.getElementById(`anexo-${campo}`) ?? document.getElementById(`anexo-card-${campo}`))?.focus();
};

const continuar = (): void => {
    tentouContinuar.value = true;
    if (!podeContinuar.value) {
        focarPendencias();
        return;
    }

    // Caminho da rota cadastro.anexos.concluir. O servidor confere de novo: se algo mudou desde que a tela abriu, a pessoa
    // fica aqui e as props novas trazem as pendências.
    router.post(
        '/cadastro/anexos/concluir',
        {},
        {
            // Na etapa seguinte a tela começa do topo; se a pessoa ficar aqui, a rolagem fica.
            preserveScroll: (pagina) => pagina.component === 'Cadastro/Anexos',
            onStart: () => {
                continuando.value = true;
            },
            onFinish: () => {
                continuando.value = false;
            },
            onSuccess: focarPendencias,
        },
    );
};

const classeBotao =
    'pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill px-8 text-center text-[0.8125rem] font-bold tracking-[0.12em] uppercase no-underline disabled:cursor-not-allowed disabled:opacity-70';
</script>

<template>
    <div>
        <Head title="Anexos" />

        <FaixaPortal aria-labelledby="anexos-titulo">
            <!-- No modo edição, o mesmo cabeçalho das outras etapas. -->
            <SaudacaoCadastro v-if="finalizado" id-titulo="anexos-titulo">Confira e atualize seus documentos abaixo.</SaudacaoCadastro>
            <div v-else class="flex flex-col gap-3">
                <p class="text-[0.75rem] font-semibold tracking-[0.24em] text-white/75 uppercase">Etapa 3 de 5 · Anexos</p>
                <h1 id="anexos-titulo" class="font-barlow text-[2.6rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase md:text-[3.5rem]">
                    Anexos
                </h1>
                <p class="max-w-2xl text-base leading-[1.7] text-white/85 md:text-[1.0625rem]">
                    Envie as cópias dos seus documentos. Cada arquivo é enviado assim que você o escolhe.
                </p>
            </div>
            <template #abaixo>
                <EtapasCadastro :atual="3" :navegavel="finalizado" />
            </template>
        </FaixaPortal>

        <div id="formulario-anexos" class="relative mx-auto -mt-20 flex max-w-6xl flex-col gap-6 px-4 pb-16 md:-mt-24">
            <SecaoCadastro id="secao-anexos" :numero="1" titulo="Anexos">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                    </svg>
                </template>

                <p v-if="algumConferidoPelaIa" class="mb-5 text-[0.875rem] leading-[1.5] text-tinta-suave">
                    Alguns documentos são conferidos automaticamente logo depois do envio. O resultado aparece ao lado de cada um em poucos segundos.
                </p>

                <!-- Um anexo por linha, os obrigatórios primeiro, numa lista só separada por linhas finas. -->
                <ul class="divide-y divide-hairline overflow-hidden rounded-[16px] border border-hairline">
                    <li v-for="anexo in emOrdem" :key="anexo.campo">
                        <CampoAnexoCadastro
                            :anexo="anexo"
                            :situacao="situacoes[anexo.campo] ?? null"
                            :ocupado="ocupado"
                            :erro="erroDoAnexo(anexo)"
                            :finalizado="finalizado"
                            :pendente="pendente(anexo.campo)"
                        />
                    </li>
                </ul>

                <p class="mt-3 text-[0.8125rem] text-tinta-suave">
                    <span class="text-erro-texto" aria-hidden="true">*</span> Obrigatório. Você também pode arrastar um arquivo direto para a linha do documento.
                </p>
            </SecaoCadastro>

            <div v-if="!finalizado" class="flex flex-col gap-4">
                <div
                    v-if="tentouContinuar && pendencias.length > 0"
                    :id="ID_PENDENCIAS"
                    role="alert"
                    tabindex="-1"
                    class="rounded-[12px] border border-invalido-texto/40 bg-invalido-fundo px-4 py-3 text-invalido-texto"
                >
                    <p class="font-semibold">Para continuar, resolva:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li v-for="pendencia in pendencias" :key="pendencia.campo">
                            <a :href="`#anexo-${pendencia.campo}`" class="font-semibold text-invalido-texto underline" @click.prevent="irParaAnexo(pendencia.campo)">
                                {{ pendencia.texto }}
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <!-- Caminho da rota cadastro (Meus dados). Durante um envio vira botão desabilitado: sair cancelaria o envio. -->
                    <component
                        :is="ocupado ? 'button' : Link"
                        :href="ocupado ? undefined : '/cadastro'"
                        :type="ocupado ? 'button' : undefined"
                        :disabled="ocupado || undefined"
                        :class="[classeBotao, 'border border-tinta-suave/70 bg-superficie text-home-tinta hover:border-acao hover:text-acao']"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 12H5M11 6l-6 6 6 6" />
                        </svg>
                        Voltar
                    </component>

                    <button type="button" :disabled="ocupado" :class="[classeBotao, 'bg-acao text-sobre-acao hover:bg-acao-escuro']" @click="continuar">
                        {{ continuando ? 'Continuando…' : 'Continuar' }}
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
