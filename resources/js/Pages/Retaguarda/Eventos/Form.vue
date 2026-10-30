<script setup lang="ts">
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AvisoCamposObrigatorios from '../../../Components/AvisoCamposObrigatorios.vue';
import CampoArquivo from '../../../Components/CampoArquivo.vue';
import CampoCadastro from '../../../Components/CampoCadastro.vue';
import { focarPrimeiroErro } from '../../../Components/focarPrimeiroErro';
import MapaLocal from '../../../Components/MapaLocal.vue';
import { consultar } from '../../../Components/Organizacao/mascaras';
import SecaoCadastro from '../../../Components/SecaoCadastro.vue';
import BarraAcoes from '../../../Components/Retaguarda/BarraAcoes.vue';
import PaginaRetaguarda from '../../../Components/Retaguarda/PaginaRetaguarda.vue';
import RetaguardaLayout from '../../../Layouts/RetaguardaLayout.vue';
import type { RetaguardaEventosFormProps } from '../../../types';

// O editor (Tiptap) é pesado: vai num pedaço separado, carregado só nesta tela.
const EditorTextoRico = defineAsyncComponent(() => import('../../../Components/EditorTextoRico.vue'));

defineOptions({ layout: RetaguardaLayout });

const props = defineProps<RetaguardaEventosFormProps>();

// Caminhos das rotas retaguarda.eventos.* (o front não tem helper de rotas nomeadas).
const BASE = '/retaguarda/eventos';

const salvo = props.evento;
const editando = salvo !== null;

// A imagem só vai quando escolhida; sem novo upload o servidor mantém a atual.
const form = useForm({
    titulo: salvo?.titulo ?? '',
    resumo: salvo?.resumo ?? '',
    descricao: salvo?.descricao ?? '',
    data_realizacao: salvo?.data_realizacao ?? '',
    hora_inicio: salvo?.hora_inicio ?? '',
    hora_fim: salvo?.hora_fim ?? '',
    local: salvo?.local ?? '',
    endereco: salvo?.endereco ?? '',
    latitude: salvo?.latitude ?? (null as number | null),
    longitude: salvo?.longitude ?? (null as number | null),
    publicado: salvo?.publicado ?? false,
    imagem: null as File | null,
    remover_imagem: false,
});

// Pin do mapa: a busca pelo endereço (Nominatim, via servidor) só sugere; o gestor confere e arrasta.
type RespostaLocalizacao = { encontrado: false } | { encontrado: true; dados: { latitude: number; longitude: number; descricao: string | null } };

const busca = reactive({ carregando: false, mensagem: '', erro: false });

const marcar = ({ latitude, longitude }: { latitude: number; longitude: number }): void => {
    form.latitude = latitude;
    form.longitude = longitude;
    form.clearErrors('latitude', 'longitude');
};

const localizar = async (): Promise<void> => {
    const termo = (form.endereco || form.local).trim();
    if (termo.length < 3) {
        Object.assign(busca, { mensagem: 'Preencha o endereço ou o local para localizar no mapa.', erro: true });
        return;
    }

    Object.assign(busca, { carregando: true, mensagem: '', erro: false });
    const resposta = await consultar<RespostaLocalizacao>(`${BASE}/localizar?endereco=${encodeURIComponent(termo)}`);
    busca.carregando = false;

    if (resposta?.encontrado) {
        marcar(resposta.dados);
        Object.assign(busca, { mensagem: 'Local encontrado. Confira o pin e arraste se precisar.', erro: false });
    } else {
        Object.assign(busca, { mensagem: 'Não encontramos esse endereço. Marque o local clicando no mapa.', erro: true });
    }
};

const removerMarcacao = (): void => {
    form.latitude = null;
    form.longitude = null;
    busca.mensagem = '';
};

// Coordenadas digitadas ou coladas (ex.: copiadas do Google Maps, "-12.977, -38.5147"); o campo acompanha o pin.
const formatarCoordenadas = (latitude: number | null, longitude: number | null): string =>
    latitude === null || longitude === null ? '' : `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;

const coordenadas = ref<string>(formatarCoordenadas(form.latitude, form.longitude));
const erroCoordenadas = ref<string>('');

watch(
    () => [form.latitude, form.longitude] as const,
    ([latitude, longitude]) => (coordenadas.value = formatarCoordenadas(latitude, longitude)),
);

const aplicarCoordenadas = (): void => {
    erroCoordenadas.value = '';
    const texto = coordenadas.value.trim();
    if (texto === '') {
        removerMarcacao();
        return;
    }

    // Aceita ponto ou vírgula decimal, separados por vírgula, ponto e vírgula ou espaço.
    const partes = texto.match(/^(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)$/);
    const [latitude, longitude] = partes ? [partes[1], partes[2]].map((numero) => Number(numero.replace(',', '.'))) : [NaN, NaN];

    if (Number.isNaN(latitude) || Number.isNaN(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
        erroCoordenadas.value = 'Use latitude e longitude, por exemplo: -12.977001, -38.514773.';
        return;
    }

    marcar({ latitude, longitude });
    busca.mensagem = '';
};

// O servidor erra latitude e longitude juntas; a mensagem fica embaixo do mapa e o campo de coordenadas é quem a recebe.
const erroDoMapa = computed<string | undefined>(() => form.errors.latitude || form.errors.longitude || undefined);
const ID_ERRO_DO_MAPA = 'coordenadas-erro-mapa';
const descricaoCoordenadas = (descricao?: string): string | undefined => [descricao, erroDoMapa.value && ID_ERRO_DO_MAPA].filter(Boolean).join(' ') || undefined;

// Horário de término exige o de início (required_with no servidor).
const exigeHoraInicio = computed<boolean>(() => form.hora_fim !== '');

// Limite do servidor (max em KB na validação): o arquivo grande nem sai do navegador.
const LIMITE_IMAGEM = 5 * 1024 * 1024;

const escolherImagem = (arquivo: File | null): void => {
    form.clearErrors('imagem');

    if (arquivo && arquivo.size > LIMITE_IMAGEM) {
        form.imagem = null;
        form.setError('imagem', 'A imagem deve ter no máximo 5 MB.');
        return;
    }
    form.imagem = arquivo;
    if (arquivo) form.remover_imagem = false;
};

// Edição vai como POST com _method=put: PUT com multipart não chega ao PHP.
const salvar = (): void => {
    const opcoes = {
        forceFormData: true,
        preserveScroll: true,
        onError: () => focarPrimeiroErro('#formulario-evento'),
    };

    if (editando) {
        form.transform((dados) => ({ ...dados, _method: 'put' })).post(`${BASE}/${salvo.slug}`, opcoes);
    } else {
        form.post(BASE, opcoes);
    }
};

const textoBotao = computed(() => {
    if (!form.processing) return 'Salvar evento';
    return form.progress?.percentage !== undefined ? `Enviando ${form.progress.percentage}%` : 'Salvando…';
});

const classeMarcacao = 'flex min-h-11 cursor-pointer items-start gap-3 py-2 text-[0.9375rem] leading-[1.5]';
const classeCaixa = 'mt-0.5 size-5 shrink-0 cursor-pointer accent-acao';
</script>

<template>
    <PaginaRetaguarda largura="estreita" :titulo="salvo?.titulo ?? 'Novo evento'" :trilha="[{ texto: editando ? 'Editar' : 'Novo' }]">
        <Head :title="editando ? 'Editar evento · Retaguarda' : 'Novo evento · Retaguarda'" />

        <form id="formulario-evento" novalidate class="flex flex-col gap-6" @submit.prevent="salvar">
            <SecaoCadastro id="secao-evento-dados" :numero="1" titulo="Dados do evento">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="16" rx="2" />
                        <path d="M3 10h18M8 3v4M16 3v4" />
                    </svg>
                </template>

                <AvisoCamposObrigatorios class="mb-5" />

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="titulo" class="md:col-span-2" rotulo="Título" obrigatorio :erro="form.errors.titulo" v-slot="{ descricao, invalido }">
                        <input
                            id="titulo"
                            v-model="form.titulo"
                            type="text"
                            maxlength="255"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('titulo')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="data_realizacao" rotulo="Data de realização" obrigatorio :erro="form.errors.data_realizacao" v-slot="{ descricao, invalido }">
                        <input
                            id="data_realizacao"
                            v-model="form.data_realizacao"
                            type="date"
                            required
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('data_realizacao')"
                        />
                    </CampoCadastro>

                    <div class="grid grid-cols-2 gap-4">
                        <CampoCadastro
                            id="hora_inicio"
                            rotulo="Horário de início"
                            :obrigatorio="exigeHoraInicio"
                            :erro="form.errors.hora_inicio"
                            v-slot="{ descricao, invalido }"
                        >
                            <input
                                id="hora_inicio"
                                v-model="form.hora_inicio"
                                type="time"
                                :required="exigeHoraInicio"
                                class="campo-retaguarda"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="form.clearErrors('hora_inicio', 'hora_fim')"
                            />
                        </CampoCadastro>
                        <CampoCadastro id="hora_fim" rotulo="Horário de término" :erro="form.errors.hora_fim" v-slot="{ descricao, invalido }">
                            <input
                                id="hora_fim"
                                v-model="form.hora_fim"
                                type="time"
                                class="campo-retaguarda"
                                :aria-invalid="invalido || undefined"
                                :aria-describedby="descricao"
                                @input="form.clearErrors('hora_inicio', 'hora_fim')"
                            />
                        </CampoCadastro>
                    </div>

                    <CampoCadastro
                        id="resumo"
                        class="md:col-span-2"
                        rotulo="Resumo"
                        ajuda="Aparece no card da página inicial."
                        obrigatorio
                        :erro="form.errors.resumo"
                        v-slot="{ descricao, invalido }"
                    >
                        <textarea
                            id="resumo"
                            v-model="form.resumo"
                            rows="3"
                            required
                            class="campo-retaguarda h-auto py-3 leading-[1.6]"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('resumo')"
                        />
                    </CampoCadastro>

                    <CampoCadastro
                        id="descricao"
                        class="md:col-span-2"
                        rotulo="Descrição"
                        ajuda="Aparece na página do evento, abaixo do resumo."
                        :erro="form.errors.descricao"
                        v-slot="{ descricao, invalido }"
                    >
                        <EditorTextoRico
                            id="descricao"
                            v-model="form.descricao"
                            rotulo="Descrição"
                            :descricao="descricao"
                            :invalido="invalido"
                            :url-imagem="`${BASE}/conteudo/imagem`"
                            @update:model-value="form.clearErrors('descricao')"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-evento-localizacao" :numero="2" titulo="Localização">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z" />
                        <circle cx="12" cy="10" r="2.5" />
                    </svg>
                </template>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <CampoCadastro id="local" rotulo="Local" ajuda="Aparece no card da página inicial." :erro="form.errors.local" v-slot="{ descricao, invalido }">
                        <input
                            id="local"
                            v-model="form.local"
                            type="text"
                            maxlength="255"
                            placeholder="Ex.: Teatro Gregório de Mattos"
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('local')"
                        />
                    </CampoCadastro>

                    <CampoCadastro id="endereco" rotulo="Endereço" :erro="form.errors.endereco" v-slot="{ descricao, invalido }">
                        <input
                            id="endereco"
                            v-model="form.endereco"
                            type="text"
                            maxlength="255"
                            placeholder="Ex.: Praça Castro Alves, s/n, Centro, Salvador/BA"
                            class="campo-retaguarda"
                            :aria-invalid="invalido || undefined"
                            :aria-describedby="descricao"
                            @input="form.clearErrors('endereco')"
                        />
                    </CampoCadastro>

                    <div class="flex flex-col gap-3 md:col-span-2">
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                :disabled="busca.carregando"
                                class="pressionavel inline-flex min-h-12 items-center justify-center gap-2 rounded-pill border border-acao bg-superficie px-6 text-[0.8125rem] font-bold tracking-[0.12em] text-acao uppercase hover:bg-nevoa disabled:cursor-wait disabled:opacity-70"
                                @click="localizar"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="7" />
                                    <path d="m20 20-3.5-3.5" />
                                </svg>
                                {{ busca.carregando ? 'Localizando…' : 'Localizar no mapa' }}
                            </button>
                            <button
                                v-if="form.latitude !== null"
                                type="button"
                                class="pressionavel inline-flex min-h-11 items-center rounded-pill px-3 text-[0.8125rem] font-bold text-acao hover:bg-nevoa"
                                @click="removerMarcacao"
                            >
                                Remover marcação
                            </button>
                        </div>
                        <p aria-live="polite" class="text-[0.9375rem]" :class="busca.erro ? 'font-semibold text-invalido-texto' : 'text-tinta-suave'">
                            {{ busca.mensagem || 'Busque pelo endereço (ou pelo local) e confira o pin. Clique no mapa ou arraste o pin para ajustar.' }}
                        </p>
                        <MapaLocal
                            :latitude="form.latitude"
                            :longitude="form.longitude"
                            editavel
                            rotulo="Mapa para marcar o local do evento"
                            class="h-80"
                            @update:posicao="marcar"
                        />
                        <p v-if="erroDoMapa" :id="ID_ERRO_DO_MAPA" role="alert" class="text-[0.9375rem] font-semibold text-invalido-texto">
                            {{ erroDoMapa }}
                        </p>
                        <p v-else-if="form.latitude === null" class="text-[0.8125rem] text-tinta-suave">Sem marcação, o evento aparece sem mapa no portal.</p>
                    </div>

                    <CampoCadastro
                        id="coordenadas"
                        class="md:col-span-2"
                        rotulo="Coordenadas (latitude, longitude)"
                        ajuda="Também dá para colar do Google Maps: clique com o botão direito no lugar e copie os números. O pin vai junto."
                        :erro="erroCoordenadas || undefined"
                        v-slot="{ descricao, invalido }"
                    >
                        <input
                            id="coordenadas"
                            v-model="coordenadas"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="Ex.: -12.977001, -38.514773"
                            class="campo-retaguarda tabular-nums md:max-w-md"
                            :aria-invalid="invalido || erroDoMapa ? true : undefined"
                            :aria-describedby="descricaoCoordenadas(descricao)"
                            @change="aplicarCoordenadas"
                            @keydown.enter.prevent="aplicarCoordenadas"
                        />
                    </CampoCadastro>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-evento-imagem" :numero="3" titulo="Imagem">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 16V4M7 9l5-5 5 5" />
                        <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                    </svg>
                </template>

                <div class="flex min-w-0 flex-col gap-3 md:max-w-md">
                    <CampoCadastro id="imagem" rotulo="Imagem do evento" :erro="form.errors.imagem" v-slot="{ descricao, invalido }">
                        <CampoArquivo
                            id="imagem"
                            accept="image/jpeg,image/png,image/webp"
                            dica="JPG, PNG ou WEBP, até 5 MB"
                            :arquivo="form.imagem"
                            :enviado="Boolean(salvo?.imagemUrl) && !form.remover_imagem"
                            :descricao="descricao"
                            :invalido="invalido"
                            @escolher="escolherImagem"
                        />
                    </CampoCadastro>
                    <div v-if="salvo?.imagemUrl && !form.imagem" class="flex items-start gap-4">
                        <img
                            :src="salvo.imagemUrl"
                            alt="Imagem atual do evento"
                            class="h-24 w-36 shrink-0 rounded-[12px] border border-hairline object-cover"
                            :class="{ 'opacity-40': form.remover_imagem }"
                        />
                        <label :class="classeMarcacao">
                            <input v-model="form.remover_imagem" type="checkbox" :class="classeCaixa" />
                            Remover imagem
                        </label>
                    </div>
                </div>
            </SecaoCadastro>

            <SecaoCadastro id="secao-evento-publicacao" :numero="4" titulo="Publicação">
                <template #icone>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
                    </svg>
                </template>

                <label :class="classeMarcacao">
                    <input v-model="form.publicado" type="checkbox" :class="classeCaixa" aria-describedby="publicado-ajuda" />
                    <span>
                        <span class="font-semibold">Publicar no portal</span>
                        <span id="publicado-ajuda" class="block text-[0.8125rem] text-tinta-suave">O evento aparece na página inicial depois da data de realização.</span>
                    </span>
                </label>
            </SecaoCadastro>
            <BarraAcoes :cancelar="BASE" :salvar="textoBotao" :processando="form.processing" :com-erros="form.hasErrors" />
        </form>
    </PaginaRetaguarda>
</template>
