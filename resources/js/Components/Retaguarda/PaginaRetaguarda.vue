<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AcessibilidadeRetaguarda from './AcessibilidadeRetaguarda.vue';
import { localNoMenu } from './menuRetaguarda';

type PassoTrilha = { texto: string; href?: string };

const props = withDefaults(
    defineProps<{
        titulo: string;
        /** Uma linha abaixo do título. */
        descricao?: string;
        /** Passos depois do item do menu (ex.: o nome do registro em edição). Sem eles, o item é o último passo. */
        trilha?: PassoTrilha[];
        /** Grupo da trilha nas telas fora do menu (perfil, senha). */
        grupo?: string;
        /** 'larga' para listas; 'estreita' para formulários. */
        largura?: 'larga' | 'estreita';
    }>(),
    { trilha: () => [], largura: 'larga' },
);

const page = usePage();
const local = computed(() => localNoMenu(page.url));

// Retaguarda › Grupo › Item › passos da página (o Painel não tem grupo). O último passo é a página atual e não é link.
const passos = computed<PassoTrilha[]>(() => {
    const doMenu: PassoTrilha[] = local.value
        ? [...(local.value.grupo ? [{ texto: local.value.grupo.texto }] : []), { texto: local.value.item.texto, href: local.value.item.href }]
        : props.grupo
          ? [{ texto: props.grupo }]
          : [];
    const todos = [...doMenu, ...props.trilha];
    return todos.map((passo, indice) => (indice === todos.length - 1 ? { texto: passo.texto } : passo));
});

const classeLargura = computed<string>(() => (props.largura === 'larga' ? 'max-w-[90rem]' : 'max-w-5xl'));
</script>

<template>
    <!-- Página da retaguarda: faixa azul-marinho (trilha, acessibilidade, título e ações) e o conteúdo em painéis
         claros, que sobem sobre a base da faixa. As quebras seguem a largura da página (@container), que muda com o
         menu lateral aberto ou recolhido, e não a da tela. -->
    <div class="@container flex flex-col">
        <section class="barra-institucional relative bg-noite pb-24 text-white [html.alto-contraste_&]:bg-fundo">
            <div aria-hidden="true" class="home-veu pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute inset-0 bg-[radial-gradient(60%_140%_at_92%_-20%,rgba(0,92,169,0.65)_0%,rgba(0,92,169,0)_70%)]"></div>
                <div class="absolute inset-0 bg-[radial-gradient(40%_80%_at_0%_110%,rgba(0,92,169,0.28)_0%,rgba(0,92,169,0)_70%)]"></div>
                <!-- Grade fina, que some para baixo: textura de sistema sem competir com o título. -->
                <div
                    class="absolute inset-0 opacity-[0.07] [background-image:linear-gradient(rgba(255,255,255,0.9)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.9)_1px,transparent_1px)] [background-size:40px_40px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                ></div>
            </div>

            <div class="relative mx-auto px-4 @xl:px-6 @4xl:px-10" :class="classeLargura">
                <div class="flex min-h-16 items-center justify-between gap-4 py-3">
                    <nav aria-label="Trilha" class="min-w-0">
                        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-white/70">
                            <li class="flex items-center gap-2">
                                <span class="font-semibold text-white">Retaguarda</span>
                            </li>
                            <li v-for="(passo, indice) in passos" :key="indice" class="flex min-w-0 items-center gap-2">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5 shrink-0 text-white/40" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 6 6 6-6 6" />
                                </svg>
                                <Link v-if="passo.href" :href="passo.href" class="rounded-sm hover:text-white hover:underline">{{ passo.texto }}</Link>
                                <span v-else class="truncate" :aria-current="indice === passos.length - 1 ? 'page' : undefined">{{ passo.texto }}</span>
                            </li>
                        </ol>
                    </nav>

                    <AcessibilidadeRetaguarda class="shrink-0" />
                </div>

                <div class="flex flex-col gap-6 pt-6 pb-2 @3xl:pt-8 @4xl:flex-row @4xl:items-end @4xl:justify-between">
                    <div class="min-w-0">
                        <h1 class="font-barlow text-[2.125rem] leading-[0.95] font-extrabold tracking-[0.01em] break-words uppercase @3xl:text-[2.75rem]">
                            {{ titulo }}
                        </h1>
                        <p v-if="descricao" class="mt-2.5 max-w-2xl text-[0.9375rem] leading-relaxed text-white/80">{{ descricao }}</p>
                        <div v-if="$slots.detalhe" class="mt-3 flex flex-wrap items-center gap-2">
                            <slot name="detalhe" />
                        </div>
                    </div>

                    <div v-if="$slots.acoes" class="flex shrink-0 flex-col gap-3 @lg:flex-row @lg:flex-wrap @4xl:justify-end">
                        <slot name="acoes" />
                    </div>
                </div>
            </div>
        </section>

        <div class="relative mx-auto -mt-16 flex w-full flex-col gap-6 px-4 pb-16 @xl:px-6 @4xl:px-10" :class="classeLargura">
            <slot />
        </div>
    </div>
</template>
