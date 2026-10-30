<script lang="ts">
// Item em destaque na tela anterior. Cada tela monta a própria FaixaPortal (e este menu) outra vez,
// então o destaque nasce no item de antes e desliza até o atual.
let indiceAnterior = -1;
</script>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

type ItemMenu = { href: string; texto: string; ativoEm: string[]; icone: string };

// Caminhos das rotas cadastro, portal.editais, portal.inscricoes.index e portal.projetos (o front não tem helper de rotas
// nomeadas).
const cadastro: ItemMenu = {
    href: '/cadastro',
    texto: 'Cadastro',
    ativoEm: ['/cadastro'],
    icone: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
};
const editais: ItemMenu = {
    href: '/portal/editais',
    texto: 'Editais',
    ativoEm: ['/portal/editais'],
    icone: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h6',
};
// Também fica em destaque dentro de uma inscrição (/portal/inscricoes/{id}).
const minhasInscricoes: ItemMenu = {
    href: '/portal/inscricoes',
    texto: 'Minhas inscrições',
    ativoEm: ['/portal/inscricoes'],
    icone: 'M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1zM8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 14l2 2 4-4',
};
const projetos: ItemMenu = {
    href: '/portal/projetos',
    texto: 'Projetos',
    ativoEm: ['/portal/projetos'],
    icone: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
};

const page = usePage();
const tiposCadastro = computed(() => page.props.auth.user?.tiposCadastro ?? []);

// Editais e Minhas inscrições são do proponente e Projetos, do patrocinador (quem marcou os dois vê os dois); Cadastro,
// comum a todos, vem por último.
const itens = computed<ItemMenu[]>(() => {
    if (!tiposCadastro.value.length) return [];
    return [
        ...(tiposCadastro.value.includes('proponente') ? [editais, minhasInscricoes] : []),
        ...(tiposCadastro.value.includes('patrocinador') ? [projetos] : []),
        cadastro,
    ];
});

const naRota = (caminho: string): boolean => page.url === caminho || page.url.startsWith(`${caminho}/`) || page.url.startsWith(`${caminho}?`);
const atual = (item: ItemMenu): boolean => item.ativoEm.some(naRota);

// Os itens têm a mesma largura: a posição do destaque é só o índice do item.
const indiceAtual = computed(() => itens.value.findIndex(atual));
const destaque = ref<HTMLElement | null>(null);
const indiceDestaque = ref<number>(indiceAnterior >= 0 && indiceAnterior < itens.value.length ? indiceAnterior : indiceAtual.value);
const estiloDestaque = computed(() => ({
    width: `calc((100% - 0.75rem) / ${itens.value.length})`,
    transform: `translateX(${indiceDestaque.value * 100}%)`,
}));

onMounted(() => {
    // Ler a largura força o navegador a calcular a posição de partida; só assim a troca vira transição.
    void destaque.value?.offsetWidth;
    indiceDestaque.value = indiceAtual.value;
    indiceAnterior = indiceAtual.value;
});
</script>

<template>
    <!-- Na FaixaPortal, à direita do título (no celular, acima dele). Só depois do cadastro finalizado
         (tipo escolhido na etapa 4). -->
    <nav v-if="itens.length" aria-label="Portal" class="w-full sm:w-fit">
        <div class="vidro vidro-escuro vidro-pilula relative rounded-pill p-1.5">
            <span
                v-if="indiceAtual >= 0"
                ref="destaque"
                aria-hidden="true"
                class="botao-claro absolute inset-y-1.5 left-1.5 rounded-pill bg-white shadow-[0_8px_22px_-8px_rgba(255,255,255,0.5)] transition-transform duration-500 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none"
                :style="estiloDestaque"
            ></span>
            <ul class="relative flex">
                <li v-for="item in itens" :key="item.href" class="min-w-0 flex-1 sm:w-36 sm:flex-none">
                    <Link
                        :href="item.href"
                        class="pressionavel flex min-h-14 flex-col items-center justify-center gap-1 rounded-pill px-1 text-center text-[0.625rem] font-bold tracking-[0.06em] text-white/80 uppercase no-underline transition-colors hover:text-white aria-[current=page]:text-home-azul sm:min-h-11 sm:flex-row sm:gap-2 sm:px-3 sm:text-[0.75rem] sm:tracking-[0.12em] [html.alto-contraste_&]:aria-[current=page]:text-sobre-acao"
                        :aria-current="atual(item) ? 'page' : undefined"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0 sm:size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="item.icone" />
                        </svg>
                        {{ item.texto }}
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
