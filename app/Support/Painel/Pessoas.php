<?php

namespace App\Support\Painel;

use App\Enums\Escolaridade;
use App\Enums\FaixaDeRenda;
use App\Enums\IdentidadeGenero;
use App\Enums\PovoTradicional;
use App\Enums\RacaCor;
use App\Enums\TipoDeficiencia;
use App\Models\AreaAtuacao;
use App\Models\PrefeituraBairro;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Consulta;
use Illuminate\Support\Str;

/**
 * Perfil das pessoas do portal (proponentes e patrocinadores), pelo cadastro de pessoa física.
 *
 * @phpstan-import-type Fatia from ConsultasDoPainel
 * @phpstan-import-type Distribuicao from ConsultasDoPainel
 * @phpstan-import-type Faixa from ConsultasDoPainel
 */
final class Pessoas
{
    /** Anos de atuação na cultura: [mínimo, máximo], máximo nulo para "ou mais". */
    private const array FAIXAS_DE_ATUACAO = [
        'Até 2 anos' => [0, 2],
        '3 a 5 anos' => [3, 5],
        '6 a 10 anos' => [6, 10],
        '11 a 20 anos' => [11, 20],
        '21 a 30 anos' => [21, 30],
        'Mais de 30 anos' => [31, null],
    ];

    /** Idade em anos completos, nas mesmas faixas dos avaliadores (Capacidade). */
    private const array FAIXAS_DE_IDADE = [
        'Até 29 anos' => [0, 29],
        '30 a 39 anos' => [30, 39],
        '40 a 49 anos' => [40, 49],
        '50 a 59 anos' => [50, 59],
        '60 anos ou mais' => [60, null],
    ];

    /** Grupos do pictograma de gênero: valor => [rótulo, identidades]. */
    private const array GRUPOS_DE_GENERO = [
        'mulheres' => ['Mulheres', ['mulher_cis', 'mulher_trans']],
        'homens' => ['Homens', ['homem_cis', 'homem_trans']],
        'outras' => ['Travestis, não binárias e outras', ['travesti', 'nao_binaria', 'outra']],
        'nao_informada' => ['Preferem não informar', ['nao_informada']],
    ];

    /** Sim/não do cadastro que viram indicador: coluna => rótulo. */
    private const array MARCAS = [
        'cultura_principal_renda' => 'Têm a cultura como principal fonte de renda',
        'representa_coletivo' => 'Representam um coletivo',
        'ja_foi_avaliador' => 'Já avaliaram projetos culturais',
        'interesse_avaliador' => 'Querem ser avaliadoras de projetos',
    ];

    private const int CIDADES_NA_LISTA = 8;

    /**
     * @return array{
     *     total: int,
     *     genero: Distribuicao,
     *     gruposDeGenero: list<Fatia>,
     *     racaCor: Distribuicao,
     *     negras: array{total: int, percentual: float},
     *     idade: array{faixas: list<Faixa>, respostas: int, semResposta: int},
     *     escolaridade: Distribuicao,
     *     renda: Distribuicao,
     *     deficiencia: array{total: int, percentual: float, tipos: Distribuicao},
     *     povos: array{total: int, percentual: float, itens: list<Fatia>},
     *     atuacao: list<Faixa>,
     *     areas: Distribuicao,
     *     indicadores: list<Fatia>,
     *     moradia: array{salvador: int, percentual: float, cidades: list<array{rotulo: string, total: int, percentual: float}>},
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $pessoas = $consultas->pessoas();
        $total = (clone $pessoas)->count();
        $genero = $consultas->distribuicao($pessoas, 'pessoas_fisicas.identidade_genero', IdentidadeGenero::class);
        $racaCor = $consultas->distribuicao($pessoas, 'pessoas_fisicas.raca_cor', RacaCor::class);
        $povos = $consultas->distribuicao($pessoas, 'pessoas_fisicas.povo_tradicional', PovoTradicional::class);
        $comDeficiencia = (clone $pessoas)->where('pessoas_fisicas.pcd', true);
        $pcd = (clone $comDeficiencia)->count();
        $negras = self::somaDe($racaCor, ['preta', 'parda']);
        $pertencemAPovos = $povos['respostas'] - self::somaDe($povos, [PovoTradicional::NaoPertence->value]);

        return [
            'total' => $total,
            'genero' => $genero,
            'gruposDeGenero' => array_map(function (string $valor, array $grupo) use ($genero): array {
                $soma = self::somaDe($genero, $grupo[1]);

                return [
                    'valor' => $valor,
                    'rotulo' => $grupo[0],
                    'total' => $soma,
                    'percentual' => ConsultasDoPainel::percentual($soma, $genero['respostas']),
                ];
            }, array_keys(self::GRUPOS_DE_GENERO), array_values(self::GRUPOS_DE_GENERO)),
            'racaCor' => $racaCor,
            'negras' => ['total' => $negras, 'percentual' => ConsultasDoPainel::percentual($negras, $racaCor['respostas'])],
            'idade' => self::idade($consultas, $pessoas),
            'escolaridade' => $consultas->distribuicao($pessoas, 'pessoas_fisicas.escolaridade', Escolaridade::class),
            'renda' => $consultas->distribuicao($pessoas, 'pessoas_fisicas.renda', FaixaDeRenda::class),
            'deficiencia' => [
                'total' => $pcd,
                'percentual' => ConsultasDoPainel::percentual($pcd, $total),
                'tipos' => $consultas->distribuicao($comDeficiencia, 'pessoas_fisicas.deficiencia', TipoDeficiencia::class),
            ],
            'povos' => [
                'total' => $pertencemAPovos,
                'percentual' => ConsultasDoPainel::percentual($pertencemAPovos, $povos['respostas']),
                'itens' => collect($povos['itens'])
                    ->reject(fn (array $item): bool => $item['valor'] === PovoTradicional::NaoPertence->value || $item['total'] === 0)
                    ->sortByDesc('total')
                    ->map(fn (array $item): array => [...$item, 'percentual' => ConsultasDoPainel::percentual($item['total'], $pertencemAPovos)])
                    ->values()
                    ->all(),
            ],
            'atuacao' => ConsultasDoPainel::emFaixas($consultas->totaisPor($pessoas, 'pessoas_fisicas.anos_atuacao'), self::FAIXAS_DE_ATUACAO),
            'areas' => self::areas($consultas, $pessoas, $total),
            'indicadores' => self::indicadores($pessoas, $total),
            'moradia' => self::moradia($consultas, $pessoas, $total),
        ];
    }

    /**
     * Faixas de idade de quem informou a data de nascimento. Os totais vêm por data, agrupados na própria coluna (sem
     * função nem bind no GROUP BY), e a idade de cada data sai aqui.
     *
     * @return array{faixas: list<Faixa>, respostas: int, semResposta: int}
     */
    private static function idade(ConsultasDoPainel $consultas, Consulta $pessoas): array
    {
        $hoje = CarbonImmutable::now(config('app.timezone'));
        $porIdade = [];
        $semResposta = 0;

        foreach ($consultas->totaisPor($pessoas, 'pessoas_fisicas.data_nascimento') as $nascimento => $quantas) {
            if ($nascimento === '') {
                $semResposta += $quantas;

                continue;
            }
            // O Oracle devolve a data com a hora (meia-noite); o SQLite, só a data.
            $idade = self::anosCompletos(substr((string) $nascimento, 0, 10), $hoje);
            $porIdade[$idade] = ($porIdade[$idade] ?? 0) + $quantas;
        }

        return [
            'faixas' => ConsultasDoPainel::emFaixas($porIdade, self::FAIXAS_DE_IDADE),
            'respostas' => array_sum($porIdade),
            'semResposta' => $semResposta,
        ];
    }

    /** Anos completos no dia de hoje, pelo calendário (sem fuso nem horário de verão); 29/02 completa em 01/03 nos anos comuns. */
    private static function anosCompletos(string $nascimento, CarbonImmutable $hoje): int
    {
        $anos = $hoje->year - (int) substr($nascimento, 0, 4);

        return $hoje->format('m-d') < substr($nascimento, 5, 5) ? $anos - 1 : $anos;
    }

    /**
     * Pessoas por área de atuação, da mais marcada para a menos, com as ativas que ninguém marcou. Cada pessoa marca
     * quantas quiser: o percentual é sobre quem marcou alguma, e a soma passa de 100%.
     *
     * @return Distribuicao
     */
    private static function areas(ConsultasDoPainel $consultas, Consulta $pessoas, int $total): array
    {
        $porArea = $consultas->totaisPor(
            (clone $pessoas)->join('area_atuacao_pessoa_fisica', 'area_atuacao_pessoa_fisica.pessoa_fisica_id', '=', 'pessoas_fisicas.id'),
            'area_atuacao_pessoa_fisica.area_atuacao_id',
        );
        $respostas = (clone $pessoas)
            ->whereExists(fn (Consulta $marcacao) => $marcacao->selectRaw('1')
                ->from('area_atuacao_pessoa_fisica')
                ->whereColumn('area_atuacao_pessoa_fisica.pessoa_fisica_id', 'pessoas_fisicas.id'))
            ->count();
        // Desativada continua contando para quem a marcou antes.
        $areas = AreaAtuacao::query()
            ->where(fn (Builder $q) => $q->where('ativo', true)->orWhereIn('id', array_keys($porArea)))
            ->get(['id', 'nome']);

        return [
            // A ordenação é estável: no empate, fica a ordem das listas (pelo nome, "Outros" no fim).
            'itens' => AreaAtuacao::emOrdem($areas)
                ->map(function (AreaAtuacao $area) use ($porArea, $respostas): array {
                    $quantas = $porArea[(int) $area->id] ?? 0;

                    return [
                        'valor' => (string) $area->id,
                        'rotulo' => $area->nome,
                        'total' => $quantas,
                        'percentual' => ConsultasDoPainel::percentual($quantas, $respostas),
                    ];
                })
                ->sortByDesc('total')
                ->values()
                ->all(),
            'respostas' => $respostas,
            'semResposta' => $total - $respostas,
        ];
    }

    /**
     * As marcas de sim/não do cadastro, as pessoas trans e quem nasceu fora do Brasil, sobre o total.
     *
     * @return list<Fatia>
     */
    private static function indicadores(Consulta $pessoas, int $total): array
    {
        $contagens = [];
        foreach (self::MARCAS as $coluna => $rotulo) {
            $contagens[$coluna] = [$rotulo, (clone $pessoas)->where("pessoas_fisicas.{$coluna}", true)->count()];
        }
        $trans = ConsultasDoPainel::recortesDoPerfil()['trans'];
        $contagens['trans'] = [$trans['rotulo'], ($trans['condicao'])(clone $pessoas)->count()];
        $contagens['estrangeiras'] = ['Nasceram fora do Brasil', (clone $pessoas)->where('pessoas_fisicas.pais_origem', '<>', 'Brasil')->count()];

        return array_map(
            fn (string $valor, array $contagem): array => [
                'valor' => $valor,
                'rotulo' => $contagem[0],
                'total' => $contagem[1],
                'percentual' => ConsultasDoPainel::percentual($contagem[1], $total),
            ],
            array_keys($contagens),
            array_values($contagens),
        );
    }

    /**
     * Quantos moram em Salvador (numa Prefeitura-Bairro) e as cidades de quem mora fora.
     *
     * @return array{salvador: int, percentual: float, cidades: list<array{rotulo: string, total: int, percentual: float}>}
     */
    private static function moradia(ConsultasDoPainel $consultas, Consulta $pessoas, int $total): array
    {
        $fora = (clone $pessoas)->where('pessoas_fisicas.territorio', PrefeituraBairro::FORA_DE_SALVADOR);
        $salvador = $total - (clone $fora)->count();

        // Cidade é texto livre (ou do CEP): junta grafias que só mudam acento e caixa.
        $cidades = (clone $fora)
            ->select('pessoas_fisicas.cidade', 'pessoas_fisicas.estado')
            ->selectRaw('count(*) as total')
            ->groupBy('pessoas_fisicas.cidade', 'pessoas_fisicas.estado')
            ->get()
            ->groupBy(fn (object $linha): string => Str::slug((string) $linha->cidade).'/'.mb_strtoupper((string) $linha->estado))
            ->map(fn ($linhas): array => [
                'rotulo' => trim(mb_convert_case(trim((string) $linhas->first()->cidade), MB_CASE_TITLE).'/'.mb_strtoupper(trim((string) $linhas->first()->estado)), '/'),
                'total' => (int) $linhas->sum('total'),
            ])
            ->sortByDesc('total')
            ->take(self::CIDADES_NA_LISTA)
            ->map(fn (array $cidade): array => [...$cidade, 'percentual' => ConsultasDoPainel::percentual($cidade['total'], $total)])
            ->values()
            ->all();

        return [
            'salvador' => $salvador,
            'percentual' => ConsultasDoPainel::percentual($salvador, $total),
            'cidades' => $cidades,
        ];
    }

    /**
     * Soma dos totais dos valores da distribuição.
     *
     * @param  Distribuicao  $distribuicao
     * @param  list<string>  $valores
     */
    private static function somaDe(array $distribuicao, array $valores): int
    {
        return (int) collect($distribuicao['itens'])->whereIn('valor', $valores)->sum('total');
    }
}
