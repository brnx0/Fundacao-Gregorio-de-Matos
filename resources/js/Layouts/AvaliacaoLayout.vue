<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import CookieNotice from '../Components/CookieNotice.vue';
import FlashMessage from '../Components/FlashMessage.vue';
import SiteFooter from '../Components/SiteFooter.vue';
import SiteHeader from '../Components/SiteHeader.vue';
import SkipLink from '../Components/SkipLink.vue';
import TestEnvBanner from '../Components/TestEnvBanner.vue';
import TransicaoIris from '../Components/TransicaoIris.vue';

// Área do avaliador: o layout do portal com uma faixa do avaliador (nome e "Sair") depois que ele entra pelo link.
const page = usePage();
const ambienteTeste = computed(() => page.props.ambienteTeste);
const avaliador = computed(() => page.props.avaliador);
// Token do <meta> de app.blade.php: o "Sair" é um envio comum, que recarrega a página.
const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <SkipLink />
        <TestEnvBanner v-if="ambienteTeste" />
        <SiteHeader />

        <div v-if="avaliador" class="border-b border-hairline bg-parchment">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2.5">
                <p class="min-w-0 text-[0.9375rem]">
                    <span class="text-tinta-suave">Área do avaliador · </span>
                    <span class="font-semibold break-words">{{ avaliador.nome }}</span>
                </p>
                <!-- Rota avaliacao.sair: encerra só o acesso de avaliador. -->
                <form method="post" action="/avaliacao/sair" data-iris>
                    <input type="hidden" name="_token" :value="csrf" />
                    <button
                        type="submit"
                        class="pressionavel inline-flex min-h-11 items-center rounded-pill border border-acao px-4 text-[0.9375rem] font-semibold text-acao hover:bg-nevoa"
                    >
                        Sair<span class="sr-only"> da área do avaliador</span>
                    </button>
                </form>
            </div>
        </div>

        <main id="conteudo" tabindex="-1" class="flex-1 focus:outline-none">
            <FlashMessage />
            <slot />
        </main>

        <SiteFooter />
        <CookieNotice />
        <TransicaoIris />
    </div>
</template>
