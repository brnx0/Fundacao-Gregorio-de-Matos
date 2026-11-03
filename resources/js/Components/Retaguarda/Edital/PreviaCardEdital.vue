<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CartaoEdital from '../../CartaoEdital.vue';
import ConteudoSlideEdital from '../../ConteudoSlideEdital.vue';
import type { SituacaoEdital } from '../../../types';

const props = defineProps<{
    titulo: string;
    categoria: string;
    /** A descrição do card (o resumo do edital). */
    resumo: string;
    /** Menor início e maior fim das chamadas (aaaa-mm-dd), vazios sem chamada completa. */
    inicio: string;
    fim: string;
    /** A imagem escolhida agora ou, sem ela, a já salva (null quando não há nenhuma ou ela vai ser removida). */
    imagem: File | null;
    imagemSalva: string | null;
}>();

// A imagem escolhida é lida como data: (a CSP do site não libera blob: em img-src). Uma leitura que termina depois
// de outra troca é descartada.
const imagemLida = ref<string | null>(null);
let leitura: FileReader | null = null;
watch(
    () => props.imagem,
    (arquivo) => {
        leitura?.abort();
        imagemLida.value = null;
        if (!arquivo) return;

        const leitor = new FileReader();
        leitor.onload = () => {
            if (leitura === leitor && typeof leitor.result === 'string') imagemLida.value = leitor.result;
        };
        leitura = leitor;
        leitor.readAsDataURL(arquivo);
    },
    { immediate: true },
);
onBeforeUnmount(() => leitura?.abort());

const imagem = computed<string | null>(() => (props.imagem ? imagemLida.value : props.imagemSalva));

// Situação como o servidor calcula (Edital::situacao), pelo dia de hoje no navegador.
const ROTULOS: Record<SituacaoEdital, string> = { aberto: 'Aberto', em_breve: 'Em breve', encerrado: 'Encerrado' };
const situacao = computed<SituacaoEdital>(() => {
    const hoje = new Date().toLocaleDateString('sv-SE');
    if (!props.inicio || hoje < props.inicio) return 'em_breve';
    return hoje > props.fim ? 'encerrado' : 'aberto';
});

const data = (texto: string): string => (texto ? texto.split('-').reverse().join('/') : '—');

const edital = computed(() => ({
    slug: 'previa',
    titulo: props.titulo || 'Título do edital',
    resumo: props.resumo || null,
    categoria: props.categoria || 'Categoria',
    situacao: situacao.value,
    situacaoRotulo: ROTULOS[situacao.value],
    periodo: props.inicio ? `${data(props.inicio)} a ${data(props.fim)}` : 'Sem datas',
    inscricoesInicio: data(props.inicio),
    inscricoesFim: data(props.fim),
    imagem: imagem.value,
    pdf: '#',
}));
</script>

<template>
    <div class="flex flex-col gap-3">
        <div>
            <h4 class="text-[0.9375rem] font-semibold">Prévia do card</h4>
            <p class="text-[0.8125rem] text-tinta-suave">Como o edital aparece depois de publicado, com o que está no formulário (antes de salvar).</p>
        </div>

        <p v-if="!imagem" class="rounded-[16px] border border-dashed border-hairline px-4 py-6 text-center text-[0.9375rem] text-tinta-suave">
            Envie a imagem da vitrine para ver a prévia do card.
        </p>

        <!-- Só para olhar: os botões dos cards não funcionam aqui (inert). -->
        <div v-else inert class="flex flex-wrap items-start gap-6 rounded-[20px] bg-nevoa p-4 sm:p-6">
            <figure class="flex w-full max-w-[352px] flex-col gap-2">
                <figcaption class="text-[0.75rem] font-semibold tracking-[0.16em] text-tinta-suave uppercase">Lista de editais</figcaption>
                <CartaoEdital :edital="edital" :indice="0">
                    <template #acoes>
                        <span
                            v-if="edital.situacao === 'aberto'"
                            class="inline-flex min-h-11 items-center rounded-pill bg-acao px-4 text-[0.8125rem] font-bold text-sobre-acao"
                        >
                            Inscrever
                        </span>
                        <span class="inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-[0.8125rem] font-bold text-acao">PDF</span>
                    </template>
                </CartaoEdital>
            </figure>

            <figure class="flex w-full max-w-[380px] flex-col gap-2">
                <figcaption class="text-[0.75rem] font-semibold tracking-[0.16em] text-tinta-suave uppercase">Vitrine da página inicial</figcaption>
                <!-- O mesmo card do carrossel, sem a classe slide-edital (posição e animação): fica como o card em destaque. -->
                <ConteudoSlideEdital :edital="edital" :foto="{ src: imagem, enquadramento: 'center' }" />
            </figure>
        </div>
    </div>
</template>
