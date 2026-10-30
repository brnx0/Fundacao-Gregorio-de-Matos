<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../Components/AvisoCamposObrigatorios.vue';
import CampoCadastro from '../../Components/CampoCadastro.vue';
import FlashMessage from '../../Components/FlashMessage.vue';
import { focarPrimeiroErro } from '../../Components/focarPrimeiroErro';
import GovBrButton from '../../Components/GovBrButton.vue';
import InstitutionalBar from '../../Components/InstitutionalBar.vue';
import VidroLiquido from '../../Components/VidroLiquido.vue';
import HomeLayout from '../../Layouts/HomeLayout.vue';

// Sem o topo e o rodapé do portal: a barra institucional e, ao lado do formulário, a marca sobre o azul-marinho.
defineOptions({ layout: HomeLayout });

const form = useForm({ email: '', senha: '' });
const ano = new Date().getFullYear();

// Recusado, ou barrado pelo limite de tentativas (que o servidor devolve como erro do e-mail):
// a senha é apagada e o foco vai ao primeiro campo com erro (o e-mail, onde o servidor mostra a recusa).
const entrar = (): void => {
    form.post('/retaguarda/login', {
        onFinish: () => form.reset('senha'),
        onError: () => focarPrimeiroErro('#formulario-login'),
    });
};
</script>

<template>
    <VidroLiquido class="flex flex-1 flex-col bg-parchment text-home-tinta">
        <Head title="Entrar na retaguarda" />
        <InstitutionalBar />

        <main id="conteudo" tabindex="-1" class="grid flex-1 focus:outline-none lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
            <FlashMessage />

            <!-- Marca: faixa azul-marinho com o brilho e a grade das páginas da retaguarda. -->
            <section aria-label="Retaguarda do portal da FGM" class="barra-institucional relative flex flex-col justify-between gap-6 overflow-hidden bg-noite px-6 py-8 text-white sm:px-10 lg:px-14 lg:py-12 [html.alto-contraste_&]:bg-fundo">
                <div aria-hidden="true" class="home-veu pointer-events-none absolute inset-0">
                    <div class="absolute inset-0 bg-[radial-gradient(70%_60%_at_100%_0%,rgba(0,92,169,0.7)_0%,rgba(0,92,169,0)_70%)]"></div>
                    <div class="absolute inset-0 bg-[radial-gradient(60%_50%_at_0%_100%,rgba(0,92,169,0.35)_0%,rgba(0,92,169,0)_70%)]"></div>
                    <div
                        class="absolute inset-0 opacity-[0.07] [background-image:linear-gradient(rgba(255,255,255,0.9)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.9)_1px,transparent_1px)] [background-size:40px_40px] [mask-image:radial-gradient(ellipse_at_top_right,black,transparent_75%)]"
                    ></div>
                </div>

                <Link href="/" class="relative block w-fit py-1">
                    <img
                        src="/images/logo-fgm-prefeitura-branco.png"
                        alt="FGM – Fundação Gregório de Mattos e Prefeitura de Salvador (voltar ao site)"
                        width="1395"
                        height="210"
                        class="block h-8 w-auto max-w-full md:h-10"
                    />
                </Link>

                <div class="relative max-w-xl">
                    <p class="text-[0.75rem] font-bold tracking-[0.2em] text-white/70 uppercase">Portal da FGM</p>
                    <p class="mt-3 font-barlow text-[3rem] leading-[0.9] font-extrabold tracking-[0.01em] uppercase sm:text-[4rem] lg:text-[5rem]">Retaguarda</p>
                    <p class="mt-5 hidden max-w-md text-[1rem] leading-relaxed text-white/80 sm:block">
                        Editais, eventos, cadastros e parâmetros do portal num só lugar, para a equipe da Fundação Gregório de Mattos.
                    </p>
                </div>

                <p class="relative hidden text-[0.8125rem] text-white/60 lg:block">© {{ ano }} FGM – Fundação Gregório de Mattos · Prefeitura de Salvador</p>
            </section>

            <div class="flex items-center justify-center px-4 py-10 sm:px-8 lg:py-16">
                <section aria-labelledby="titulo-login" class="painel-retaguarda w-full max-w-md p-6 sm:p-8">
                    <h1 id="titulo-login" class="font-barlow text-[2rem] leading-none font-extrabold tracking-[0.01em] uppercase">Entrar</h1>
                    <p class="mt-2 text-[0.9375rem] text-tinta-suave">Acesso restrito aos usuários da retaguarda do portal da FGM.</p>

                    <form id="formulario-login" class="mt-6 flex flex-col gap-5" novalidate @submit.prevent="entrar">
                        <AvisoCamposObrigatorios />

                        <CampoCadastro id="email" rotulo="E-mail" obrigatorio :erro="form.errors.email" v-slot="{ descricao, invalido }">
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                required
                                class="campo-retaguarda h-12"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                            />
                        </CampoCadastro>

                        <CampoCadastro id="senha" rotulo="Senha" obrigatorio :erro="form.errors.senha" v-slot="{ descricao, invalido }">
                            <input
                                id="senha"
                                v-model="form.senha"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="campo-retaguarda h-12"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                            />
                        </CampoCadastro>

                        <button type="submit" :disabled="form.processing" class="botao-primario min-h-12">
                            {{ form.processing ? 'Entrando…' : 'Entrar' }}
                        </button>
                    </form>

                    <p class="my-6 flex items-center gap-3 text-[0.8125rem] text-tinta-suave before:h-px before:flex-1 before:bg-hairline after:h-px after:flex-1 after:bg-hairline">ou</p>

                    <GovBrButton href="/retaguarda/govbr" class="w-full" />
                </section>
            </div>
        </main>
    </VidroLiquido>
</template>
