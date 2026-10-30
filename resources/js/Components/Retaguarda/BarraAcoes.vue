<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    /** Para onde o Cancelar volta; sem ele, a barra não tem Cancelar (perfil, senha). */
    cancelar?: string;
    /** Texto do botão de salvar (a página troca por "Salvando…" enquanto envia). */
    salvar: string;
    processando: boolean;
    /** Houve erro de validação: avisa ao lado do botão. */
    comErros?: boolean;
    /** Aviso do erro; o padrão manda conferir os campos destacados. */
    mensagemErro?: string;
    /** Id do formulário, quando a barra fica fora dele. */
    formulario?: string;
}>();
</script>

<template>
    <!-- Barra de ações dos formulários: presa ao pé da tela enquanto se rola, para salvar sem procurar o botão. -->
    <div class="sticky bottom-4 z-20">
        <div class="painel-retaguarda flex flex-col-reverse gap-3 p-3 @xl:flex-row @xl:items-center @xl:justify-between @xl:pl-5 supports-[backdrop-filter]:bg-superficie/85 supports-[backdrop-filter]:backdrop-blur-xl">
            <Link v-if="cancelar" :href="cancelar" class="botao-secundario">Cancelar</Link>
            <span v-else aria-hidden="true" class="hidden @xl:block" />

            <div class="flex flex-col gap-2 @xl:flex-row @xl:items-center @xl:gap-4">
                <p v-if="comErros" role="alert" class="flex items-center gap-2 text-[0.875rem] font-semibold text-invalido-texto">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v5M12 16h.01" />
                    </svg>
                    {{ mensagemErro ?? 'Confira os campos destacados.' }}
                </p>
                <slot />
                <button type="submit" :form="formulario" :disabled="processando" class="botao-primario px-8">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12 5 5 9-10" />
                    </svg>
                    {{ salvar }}
                </button>
            </div>
        </div>
    </div>
</template>
