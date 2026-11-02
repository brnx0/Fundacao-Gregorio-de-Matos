<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import CookieNotice from '../Components/CookieNotice.vue';
import FlashMessage from '../Components/FlashMessage.vue';
import MenuAcessibilidade from '../Components/MenuAcessibilidade.vue';
import SkipLink from '../Components/SkipLink.vue';
import TestEnvBanner from '../Components/TestEnvBanner.vue';
import TransicaoIris from '../Components/TransicaoIris.vue';

// Área do avaliador: um cabeçalho só dela, como o do portal do proponente (logo, nome, acessibilidade e "Sair"), sem
// o menu do site nem o "Entrar com gov.br", que aqui só confundiriam: o avaliador entra pelo link do e-mail.
const page = usePage();
const ambienteTeste = computed(() => page.props.ambienteTeste);
const avaliador = computed(() => page.props.avaliador);
// Token do <meta> de app.blade.php: o "Sair" é um envio comum, que recarrega a página.
const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const ano = new Date().getFullYear();

// Caminhos das rotas avaliacao.inicio e das páginas do site (o front não tem helper de rotas nomeadas).
const linksRodape: { href: string; texto: string }[] = [
    { href: '/duvidas', texto: 'Dúvidas' },
    { href: '/privacidade', texto: 'Aviso de privacidade' },
    { href: '/termos', texto: 'Termos de uso' },
];
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <SkipLink />
        <TestEnvBanner v-if="ambienteTeste" />
        <div class="flex flex-1 flex-col bg-parchment text-home-tinta">
            <header class="border-b border-white/10 bg-noite text-white [html.alto-contraste_&]:bg-fundo">
                <div class="mx-auto grid max-w-6xl grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 px-4 py-3.5">
                    <!-- Com o acesso aberto, a logo leva às inscrições dele; antes (tela do CPF), ao site. -->
                    <Link :href="avaliador ? '/avaliacao' : '/'" class="block min-w-0 justify-self-start py-1 focus-visible:outline-white">
                        <img
                            src="/images/logo-fgm-prefeitura-branco.png"
                            :alt="`FGM – Fundação Gregório de Mattos e Prefeitura de Salvador (${avaliador ? 'suas inscrições' : 'voltar ao site'})`"
                            width="1395"
                            height="210"
                            class="block h-7 w-auto max-w-full md:h-9"
                        />
                    </Link>

                    <!-- No celular não há espaço: o nome fica só para o leitor de tela. -->
                    <p class="sr-only md:not-sr-only md:truncate md:text-center md:text-[1.0625rem]">
                        <span class="text-white/70">Área do avaliador</span>
                        <template v-if="avaliador">
                            <span class="text-white/70"> · </span><span class="font-semibold">{{ avaliador.nome }}</span>
                        </template>
                    </p>

                    <div class="relative col-start-3 flex w-full min-w-0 items-center justify-end gap-2 md:gap-4">
                        <MenuAcessibilidade />

                        <!-- Rota avaliacao.sair: encerra só o acesso de avaliador. -->
                        <form v-if="avaliador" method="post" action="/avaliacao/sair" data-iris>
                            <input type="hidden" name="_token" :value="csrf" />
                            <button
                                type="submit"
                                class="botao-claro pressionavel inline-flex min-h-11 items-center rounded-pill bg-white px-5 text-[0.75rem] font-bold tracking-[0.12em] text-home-azul uppercase focus-visible:outline-white"
                            >
                                Sair<span class="sr-only"> da área do avaliador</span>
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main id="conteudo" tabindex="-1" class="flex-1 focus:outline-none">
                <FlashMessage />
                <slot />
            </main>

            <footer class="border-t border-hairline bg-superficie">
                <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-8 gap-y-1 px-4 py-5 text-[0.8125rem] text-tinta-suave">
                    <p class="py-3">© {{ ano }} FGM – Fundação Gregório de Mattos · Prefeitura de Salvador</p>
                    <nav aria-label="Rodapé">
                        <ul class="flex flex-wrap gap-x-5">
                            <li v-for="link in linksRodape" :key="link.href">
                                <Link :href="link.href" class="inline-flex min-h-11 items-center hover:text-home-tinta hover:underline">{{ link.texto }}</Link>
                            </li>
                        </ul>
                    </nav>
                </div>
            </footer>
        </div>
        <CookieNotice />
        <TransicaoIris />
    </div>
</template>
