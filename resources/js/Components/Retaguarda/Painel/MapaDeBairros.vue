<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Feature, FeatureCollection, Geometry } from 'geojson';
import type { GeoJSON as CamadaGeoJson, LayerGroup, Map as MapaLeaflet, Path } from 'leaflet';
import { CALOR, degrauDoCalor } from './cores';
import { numero, quantos } from './formatos';

const props = defineProps<{
    /** Bairros oficiais com cadastro; a chave casa com a do arquivo de limites. */
    bairros: { chave: string; nome: string; total: number }[];
    /** Nome do mapa para o leitor de tela. */
    rotulo: string;
}>();

type PropriedadesDoBairro = { nome: string; chave: string };
type ArquivoDeLimites = FeatureCollection<Geometry, PropriedadesDoBairro>;

// Limites dos bairros (IBGE, Censo 2022), num JSON gerado à parte e baixado só quando o mapa aparece. O glob não quebra
// o build se o arquivo ainda não existir: o mapa avisa e o resto do painel segue.
const arquivos = import.meta.glob<{ default: ArquivoDeLimites }>('./bairros-salvador.json');

const elemento = ref<HTMLElement | null>(null);
const situacao = ref<'carregando' | 'pronto' | 'sem-limites'>('carregando');
const mostrarNomes = ref<boolean>(false);

let leaflet: typeof import('leaflet') | null = null;
let mapa: MapaLeaflet | null = null;
let camada: CamadaGeoJson<PropriedadesDoBairro> | null = null;
let nomes: LayerGroup | null = null;
let observador: MutationObserver | null = null;
let medidor: ResizeObserver | null = null;

const totais = computed<Map<string, number>>(() => new Map(props.bairros.map((bairro) => [bairro.chave, bairro.total])));
const maximo = computed<number>(() => Math.max(0, ...props.bairros.map((bairro) => bairro.total)));

/** Cor de um token do tema atual (o Leaflet pinta com cor de verdade, não com classe). */
const token = (nome: string): string => getComputedStyle(document.documentElement).getPropertyValue(`--color-${nome}`).trim();

const totalDe = (feature?: Feature<Geometry, PropriedadesDoBairro>): number => totais.value.get(feature?.properties.chave ?? '') ?? 0;

/** Contorno do bairro sem cadastro. Fixo, não um token: o mapa de fundo é sempre claro, em qualquer tema. */
const CONTORNO_SEM_CADASTRO = '#4a4a4f';

/** Bairro sem cadastro fica só no contorno, com o mapa de fundo à mostra; os pintados se separam por um traço claro. */
const estilo = (feature?: Feature<Geometry, PropriedadesDoBairro>) => {
    const degrau = degrauDoCalor(totalDe(feature), maximo.value);

    return degrau === 0
        ? { fillColor: CONTORNO_SEM_CADASTRO, fillOpacity: 0, color: CONTORNO_SEM_CADASTRO, opacity: 0.55, weight: 1 }
        : { fillColor: token(`calor-${degrau}`), fillOpacity: 0.85, color: token('superficie'), opacity: 1, weight: 1 };
};

const dicaDe = (feature?: Feature<Geometry, PropriedadesDoBairro>): string =>
    `<strong>${feature?.properties.nome ?? ''}</strong><br>${quantos(totalDe(feature), 'cadastro', 'cadastros')}`;

const pintar = (): void => {
    camada?.setStyle(estilo);
    camada?.eachLayer((layer) => layer.setTooltipContent(dicaDe((layer as Path & { feature?: Feature<Geometry, PropriedadesDoBairro> }).feature)));
};

const centralizar = (): void => {
    if (mapa && camada) mapa.fitBounds(camada.getBounds(), { padding: [12, 12] });
};

const alternarNomes = (): void => {
    mostrarNomes.value = !mostrarNomes.value;
    if (!mapa || !nomes) return;
    if (mostrarNomes.value) nomes.addTo(mapa);
    else nomes.remove();
};

onMounted(async () => {
    const carregarLimites = arquivos['./bairros-salvador.json'];
    if (!carregarLimites) {
        situacao.value = 'sem-limites';
        return;
    }

    // Leaflet e limites só baixam aqui: ficam fora do pacote das outras telas.
    const [modulo, , limites] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css'), carregarLimites()]);
    if (!elemento.value) return;

    leaflet = modulo;
    const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Sem zoom pela rodinha: a rolagem da página não fica presa no mapa.
    mapa = leaflet.map(elemento.value, {
        scrollWheelZoom: false,
        zoomAnimation: !reduzMovimento,
        fadeAnimation: !reduzMovimento,
        markerZoomAnimation: !reduzMovimento,
    });
    leaflet
        .tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: 'Limites dos bairros: IBGE, Censo 2022 · &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        })
        .addTo(mapa);

    camada = leaflet
        .geoJSON(limites.default, {
            style: estilo,
            onEachFeature: (feature, layer) => {
                layer.bindTooltip(dicaDe(feature), { sticky: true });
                layer.on('mouseover', () => (layer as Path).setStyle({ weight: 2.5, color: token('acao') }));
                layer.on('mouseout', () => camada?.resetStyle(layer));
            },
        })
        .addTo(mapa);

    nomes = leaflet.layerGroup(
        limites.default.features.map((feature) =>
            leaflet!.marker(leaflet!.geoJSON(feature).getBounds().getCenter(), {
                icon: leaflet!.divIcon({ className: 'rotulo-bairro', html: `<span>${feature.properties.nome}</span>`, iconSize: [0, 0] }),
                interactive: false,
                keyboard: false,
            }),
        ),
    );

    centralizar();
    situacao.value = 'pronto';

    // Modo noturno e alto contraste trocam as cores dos tokens: repinta o mapa junto.
    observador = new MutationObserver(pintar);
    observador.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    // O mapa cresce até a altura da coluna ao lado: o Leaflet precisa saber do tamanho novo para cobrir tudo.
    medidor = new ResizeObserver(() => mapa?.invalidateSize());
    medidor.observe(elemento.value);
});

// Filtro novo, números novos: repinta sem recriar o mapa.
watch(() => props.bairros, pintar);

onBeforeUnmount(() => {
    observador?.disconnect();
    medidor?.disconnect();
    mapa?.remove();
    mapa = null;
    camada = null;
    nomes = null;
});
</script>

<template>
    <!-- Mapa dos bairros pintado pelo número de cadastros (escala de um tom só, do claro ao escuro). isolate: as camadas
         do Leaflet (z-index 400+) ficam abaixo do cabeçalho e dos menus da página. -->
    <div class="flex flex-1 flex-col gap-3">
        <div class="relative isolate flex flex-1 flex-col overflow-hidden rounded-[16px] border border-hairline bg-nevoa">
            <div ref="elemento" role="region" :aria-label="rotulo" class="min-h-[28rem] w-full flex-1"></div>
            <div v-if="situacao === 'carregando'" aria-hidden="true" class="absolute inset-0 bg-nevoa motion-safe:animate-pulse"></div>
            <div v-else-if="situacao === 'sem-limites'" class="absolute inset-0 flex items-center justify-center p-6 text-center text-[0.875rem] text-tinta-suave">
                Os limites dos bairros ainda não foram adicionados ao sistema. Os números por bairro estão na lista ao lado.
            </div>
            <div v-if="situacao === 'pronto'" class="absolute top-3 right-3 z-[500] flex flex-col gap-2">
                <button type="button" class="botao-secundario min-h-9! px-4! text-[0.6875rem]!" :aria-pressed="mostrarNomes ? 'true' : 'false'" @click="alternarNomes">
                    {{ mostrarNomes ? 'Esconder nomes' : 'Mostrar nomes' }}
                </button>
                <button type="button" class="botao-secundario min-h-9! px-4! text-[0.6875rem]!" @click="centralizar">Centralizar</button>
            </div>
        </div>
        <div aria-hidden="true" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[0.6875rem] text-tinta-suave">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-[3px] border border-tinta-suave/55" />Sem cadastro</span>
            <span class="flex items-center gap-1.5">
                1
                <span class="flex">
                    <span v-for="degrau in [1, 2, 3, 4, 5, 6]" :key="degrau" class="h-3 w-5 first:rounded-l-[3px] last:rounded-r-[3px]" :class="CALOR[degrau].fundo" />
                </span>
                {{ numero(maximo) }} cadastros
            </span>
        </div>
    </div>
</template>
