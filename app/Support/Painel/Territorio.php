<?php

namespace App\Support\Painel;

use App\Models\Bairro;
use App\Models\PrefeituraBairro;
use Illuminate\Support\Str;

/**
 * Onde moram as pessoas do portal: por Prefeitura-Bairro e por bairro oficial, para o mapa.
 *
 * O bairro é digitado livremente (ou vem do CEP); casa com a lista oficial (tabela bairros) pela chave do nome, que
 * ignora acento, caixa e espaços. O que não casa é contado como "não reconhecido".
 */
final class Territorio
{
    /** Texto não reconhecido só aparece na lista quando se repete: um texto único pode ser um endereço inteiro. */
    private const int REPETICOES_PARA_LISTAR = 2;

    private const int TEXTOS_NA_LISTA = 10;

    /**
     * @return array{
     *     total: int,
     *     prefeituras: list<array{codigo: string, rotulo: string, total: int, percentual: float}>,
     *     bairros: list<array{chave: string, nome: string, total: int}>,
     *     emSalvador: int,
     *     mapeadas: int,
     *     naoReconhecidas: int,
     *     textosNaoReconhecidos: list<array{texto: string, total: int}>,
     *     totalDeBairros: int,
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $pessoas = $consultas->pessoas();
        $porTerritorio = $consultas->totaisPor($pessoas, 'pessoas_fisicas.territorio');
        $total = array_sum($porTerritorio);

        $oficiais = Bairro::query()->get(['nome'])->mapWithKeys(fn (Bairro $bairro): array => [self::chave($bairro->nome) => $bairro->nome]);
        $digitados = (clone $pessoas)
            ->where('pessoas_fisicas.territorio', '<>', PrefeituraBairro::FORA_DE_SALVADOR)
            ->selectRaw('LOWER(TRIM(pessoas_fisicas.bairro)) as bairro')
            ->selectRaw('count(*) as total')
            ->groupByRaw('LOWER(TRIM(pessoas_fisicas.bairro))')
            ->pluck('total', 'bairro');

        $porBairro = [];
        $naoReconhecidos = [];
        foreach ($digitados as $texto => $quantos) {
            $chave = self::chave((string) $texto);
            if ($oficiais->has($chave)) {
                $porBairro[$chave] = ($porBairro[$chave] ?? 0) + (int) $quantos;
            } else {
                $naoReconhecidos[(string) $texto] = (int) $quantos;
            }
        }
        arsort($porBairro);
        arsort($naoReconhecidos);
        $territorios = ConsultasDoPainel::territorios();

        return [
            'total' => $total,
            'prefeituras' => array_map(
                fn (string $codigo, string $rotulo): array => [
                    'codigo' => $codigo,
                    'rotulo' => $rotulo,
                    'total' => $porTerritorio[$codigo] ?? 0,
                    'percentual' => ConsultasDoPainel::percentual($porTerritorio[$codigo] ?? 0, $total),
                ],
                array_keys($territorios),
                array_values($territorios),
            ),
            'bairros' => array_map(
                fn (string $chave, int $quantos): array => ['chave' => $chave, 'nome' => $oficiais[$chave], 'total' => $quantos],
                array_keys($porBairro),
                array_values($porBairro),
            ),
            'emSalvador' => (int) $digitados->sum(),
            'mapeadas' => array_sum($porBairro),
            'naoReconhecidas' => array_sum($naoReconhecidos),
            'textosNaoReconhecidos' => collect($naoReconhecidos)
                ->filter(fn (int $quantos): bool => $quantos >= self::REPETICOES_PARA_LISTAR)
                ->take(self::TEXTOS_NA_LISTA)
                ->map(fn (int $quantos, int|string $texto): array => [
                    'texto' => $texto === '' ? '(em branco)' : mb_convert_case((string) $texto, MB_CASE_TITLE),
                    'total' => $quantos,
                ])
                ->values()
                ->all(),
            'totalDeBairros' => $oficiais->count(),
        ];
    }

    /** Chave do bairro, igual à do mapa (bairros-salvador.json): sem acento, caixa ou espaços. */
    public static function chave(string $nome): string
    {
        return Str::slug($nome);
    }
}
