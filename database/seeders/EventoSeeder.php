<?php

namespace Database\Seeders;

use App\Models\Evento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EventoSeeder extends Seeder
{
    public function run(): void
    {
        $evento = fn (string $titulo, string $resumo, string $data, ?string $local, bool $publicado = true) => [
            'titulo' => $titulo,
            'slug' => Str::slug($titulo),
            'resumo' => $resumo,
            'data_realizacao' => $data,
            'local' => $local,
            'imagem' => null,
            'publicado' => $publicado,
        ];

        // A home só mostra os próximos eventos: cada um cai na próxima vez do seu dia ("07-02" é 2 de julho).
        $proxima = function (string $mesDia): string {
            [$mes, $dia] = array_map('intval', explode('-', $mesDia));
            $data = today()->setDate(today()->year, $mes, $dia);

            return ($data->lt(today()) ? $data->addYear() : $data)->toDateString();
        };

        $eventos = [
            $evento('VII Conferência Municipal de Cultura', 'Encontro de artistas, gestores e sociedade civil para debater as políticas culturais da cidade.', $proxima('09-27'), 'Teatro Gregório de Mattos, Salvador/BA'),
            $evento('Capoeira Viva', 'Rodas, oficinas e encontros de mestres celebrando a capoeira como patrimônio de Salvador.', $proxima('09-06'), 'Espaço Cultural da Barroquinha, Salvador/BA'),
            $evento('Jornada do Patrimônio Cultural', 'Roteiros, oficinas e conversas sobre o patrimônio cultural de Salvador.', $proxima('08-15'), 'Centro Histórico, Salvador/BA'),
            $evento('Dois de Julho', 'Cortejo e programação cultural da Independência da Bahia pelas ruas da cidade.', $proxima('07-02'), 'Lapinha ao Campo Grande, Salvador/BA'),
            $evento('Sacola Literária', 'Distribuição de livros e rodas de leitura em bairros e equipamentos culturais.', $proxima('04-23'), 'Bibliotecas municipais, Salvador/BA'),
            $evento('Curto Circuito das Artes', 'Circulação de apresentações artísticas curtas por espaços da cidade.', $proxima('11-08'), 'Salvador/BA'),
            $evento('Kaê Erê — Ler Criança', 'Contação de histórias e oficinas de leitura para crianças da rede municipal.', $proxima('10-12'), 'Espaço Boca de Brasa Subúrbio 360, Salvador/BA'),
            $evento('Mostra Escolas Criativas', 'Mostra de trabalhos artísticos desenvolvidos por estudantes da rede municipal.', $proxima('06-20'), 'Salvador/BA'),
            $evento('Circuito #Reconectar', 'Circuito de ações culturais que reconecta a população aos equipamentos da FGM.', $proxima('03-29'), 'Salvador/BA'),
            $evento('Festival Boca de Brasa', 'Mostra das produções dos espaços Boca de Brasa da cidade.', now()->addMonth()->toDateString(), 'Espaço Boca de Brasa Cajazeiras, Salvador/BA'),
            // Fora da home: não publicado.
            $evento('Esqueça um Livro', 'Ação de leitura em pontos de ônibus e praças.', $proxima('08-09'), 'Salvador/BA', publicado: false),
        ];

        // Pin do mapa de cada evento (coordenadas do OpenStreetMap). Evento em "Salvador/BA" ou em vários lugares
        // ganha um ponto de referência; o Subúrbio 360 não está no mapa e fica no centro do bairro (Coutos).
        $localizacoes = [
            'vii-conferencia-municipal-de-cultura' => ['Praça Castro Alves, s/n, Centro, Salvador/BA', -12.9770006, -38.5147733],
            'capoeira-viva' => ['Ladeira da Barroquinha, Centro Histórico, Salvador/BA', -12.9773792, -38.5137469],
            'jornada-do-patrimonio-cultural' => ['Largo do Pelourinho, Centro Histórico, Salvador/BA', -12.9714690, -38.5083536],
            'dois-de-julho' => ['Saída no Largo da Lapinha, Salvador/BA', -12.9554615, -38.4999956],
            'sacola-literaria' => ['Biblioteca Municipal Edgard Santos, Av. Porto dos Mastros, Ribeira, Salvador/BA', -12.9182087, -38.4994341],
            'curto-circuito-das-artes' => ['Largo Tereza Batista, Pelourinho, Salvador/BA', -12.9732034, -38.5088593],
            'kae-ere-ler-crianca' => ['Coutos, Subúrbio Ferroviário, Salvador/BA', -12.8502501, -38.4684780],
            'mostra-escolas-criativas' => ['Casa do Benin, Pelourinho, Salvador/BA', -12.9706212, -38.5079576],
            'circuito-reconectar' => ['Parque da Cidade, Pituba, Salvador/BA', -12.9999617, -38.4720876],
            'festival-boca-de-brasa' => ['Mercado Municipal de Cajazeiras, Av. Engenheiro Raymundo Carlos Nery, Cajazeiras X, Salvador/BA', -12.8962455, -38.4091768],
            'esqueca-um-livro' => ['Praça da Piedade, Centro, Salvador/BA', -12.9831366, -38.5150430],
        ];

        foreach ($eventos as $dados) {
            $registro = Evento::withTrashed()->firstOrNew(['slug' => $dados['slug']]);

            // Só preenche o que é novo: nunca sobrescreve o que a retaguarda editou.
            if (! $registro->exists) {
                $registro->fill($dados)->save();
            }

            // O pin também só entra em evento ainda sem marcação (o endereço, só se estiver vazio).
            if (isset($localizacoes[$dados['slug']]) && ! $registro->temLocalizacao()) {
                [$endereco, $latitude, $longitude] = $localizacoes[$dados['slug']];
                $registro->fill(['endereco' => $registro->endereco ?? $endereco, 'latitude' => $latitude, 'longitude' => $longitude])->save();
            }
        }
    }
}
