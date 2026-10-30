<script setup lang="ts">
defineProps<{
    legenda: string;
    colunas: string[];
    /** A primeira célula de cada linha é o nome da linha. */
    linhas: (string | number)[][];
}>();
</script>

<template>
    <!-- Os números do gráfico em tabela, só para o leitor de tela: o desenho fica aria-hidden. -->
    <table class="sr-only">
        <caption>{{ legenda }}</caption>
        <thead>
            <tr>
                <th v-for="coluna in colunas" :key="coluna" scope="col">{{ coluna }}</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(linha, indice) in linhas" :key="indice">
                <th scope="row">{{ linha[0] }}</th>
                <td v-for="(celula, coluna) in linha.slice(1)" :key="coluna">{{ celula }}</td>
            </tr>
        </tbody>
    </table>
</template>
