<?php

namespace App\Support\Painel;

use App\Enums\NaturezaProponente;
use App\Enums\PorteOrganizacao;
use App\Support\Municipios;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as Consulta;

/**
 * Organizações (pessoas jurídicas e MEI) cadastradas no portal.
 *
 * @phpstan-import-type Fatia from ConsultasDoPainel
 * @phpstan-import-type Distribuicao from ConsultasDoPainel
 * @phpstan-import-type Faixa from ConsultasDoPainel
 */
final class Organizacoes
{
    /** Grupos da tabela de natureza jurídica da Receita, pelo primeiro dígito do código. */
    private const array GRUPOS_DA_NATUREZA = [
        '1' => 'Administração pública',
        '2' => 'Entidades empresariais',
        '3' => 'Entidades sem fins lucrativos',
        '4' => 'Pessoas físicas',
        '5' => 'Organizações internacionais',
    ];

    /** Tempo desde a fundação, em anos completos. */
    private const array FAIXAS_DE_IDADE = [
        'Menos de 2 anos' => [0, 1],
        '2 a 4 anos' => [2, 4],
        '5 a 9 anos' => [5, 9],
        '10 a 19 anos' => [10, 19],
        '20 anos ou mais' => [20, null],
    ];

    private const string SALVADOR = '2927408';

    private const int MUNICIPIOS_NA_LISTA = 8;

    /**
     * @return array{
     *     total: int,
     *     naturezas: list<Fatia>,
     *     grupos: list<Fatia>,
     *     porte: Distribuicao,
     *     idade: list<Faixa>,
     *     municipios: array{salvador: int, percentual: float, itens: list<array{rotulo: string, total: int, percentual: float}>},
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $organizacoes = $consultas->organizacoes();
        $total = (clone $organizacoes)->count();
        $porMunicipio = $consultas->totaisPor($organizacoes, 'organizacoes.municipio_ibge');
        $salvador = $porMunicipio[self::SALVADOR] ?? 0;

        return [
            'total' => $total,
            'naturezas' => self::naturezas(clone $organizacoes, $total),
            'grupos' => self::grupos($consultas->totaisPor($organizacoes, 'organizacoes.natureza_juridica'), $total),
            'porte' => $consultas->distribuicao($organizacoes, 'organizacoes.porte', PorteOrganizacao::class),
            'idade' => self::idade(clone $organizacoes),
            'municipios' => [
                'salvador' => $salvador,
                'percentual' => ConsultasDoPainel::percentual($salvador, $total),
                'itens' => collect($porMunicipio)
                    ->except(self::SALVADOR)
                    ->sortDesc()
                    ->take(self::MUNICIPIOS_NA_LISTA)
                    ->map(function (int $quantos, int|string $codigo) use ($total): array {
                        $municipio = Municipios::porCodigo((string) $codigo);

                        return [
                            'rotulo' => $municipio === null ? 'Município não informado' : "{$municipio['nome']}/{$municipio['uf']}",
                            'total' => $quantos,
                            'percentual' => ConsultasDoPainel::percentual($quantos, $total),
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * MEI, sem fins ou com fins lucrativos, pela regra de NaturezaProponente::daOrganizacao().
     *
     * @return list<Fatia>
     */
    private static function naturezas(Consulta $organizacoes, int $total): array
    {
        $porNatureza = array_fill_keys([NaturezaProponente::Mei->value, NaturezaProponente::SemFins->value, NaturezaProponente::ComFins->value], 0);

        $linhas = $organizacoes
            ->select('organizacoes.mei', 'organizacoes.finalidade_lucrativa')
            ->selectRaw('count(*) as total')
            ->groupBy('organizacoes.mei', 'organizacoes.finalidade_lucrativa')
            ->get();

        foreach ($linhas as $linha) {
            // No Oracle o booleano volta como '1'/'0'; no SQLite, como 1/0.
            $natureza = match (true) {
                (bool) $linha->mei => NaturezaProponente::Mei,
                (bool) $linha->finalidade_lucrativa => NaturezaProponente::ComFins,
                default => NaturezaProponente::SemFins,
            };
            $porNatureza[$natureza->value] += (int) $linha->total;
        }

        return array_map(fn (string $valor, int $quantos): array => [
            'valor' => $valor,
            'rotulo' => NaturezaProponente::from($valor)->rotulo(),
            'total' => $quantos,
            'percentual' => ConsultasDoPainel::percentual($quantos, $total),
        ], array_keys($porNatureza), array_values($porNatureza));
    }

    /**
     * @param  array<string, int>  $porCodigo
     * @return list<Fatia>
     */
    private static function grupos(array $porCodigo, int $total): array
    {
        $porGrupo = array_fill_keys(array_keys(self::GRUPOS_DA_NATUREZA), 0);
        foreach ($porCodigo as $codigo => $quantos) {
            $grupo = substr((string) $codigo, 0, 1);
            if (array_key_exists($grupo, $porGrupo)) {
                $porGrupo[$grupo] += $quantos;
            }
        }

        return array_map(fn (int|string $grupo, int $quantos): array => [
            'valor' => (string) $grupo,
            'rotulo' => self::GRUPOS_DA_NATUREZA[$grupo],
            'total' => $quantos,
            'percentual' => ConsultasDoPainel::percentual($quantos, $total),
        ], array_keys($porGrupo), array_values($porGrupo));
    }

    /**
     * Anos desde a fundação. As datas vêm uma a uma (organizações são poucas perto das pessoas) para não usar função de
     * data com bind no GROUP BY.
     *
     * @return list<Faixa>
     */
    private static function idade(Consulta $organizacoes): array
    {
        $hoje = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $porIdade = [];

        $organizacoes->whereNotNull('organizacoes.data_fundacao')
            ->orderBy('organizacoes.id')
            ->select('organizacoes.id', 'organizacoes.data_fundacao')
            ->chunk(1000, function ($linhas) use ($hoje, &$porIdade): void {
                foreach ($linhas as $linha) {
                    $anos = (int) CarbonImmutable::parse((string) $linha->data_fundacao)->diffInYears($hoje);
                    $porIdade[$anos] = ($porIdade[$anos] ?? 0) + 1;
                }
            });

        return ConsultasDoPainel::emFaixas($porIdade, self::FAIXAS_DE_IDADE);
    }
}
