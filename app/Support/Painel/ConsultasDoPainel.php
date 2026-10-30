<?php

namespace App\Support\Painel;

use App\Enums\SituacaoInscricao;
use App\Enums\TipoCadastro;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\Organizacao;
use App\Models\PrefeituraBairro;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Consulta;
use Illuminate\Support\Facades\DB;

/**
 * Consultas-base do Painel, já com os filtros, e as contas que as seções repetem.
 *
 * Tudo agrega no banco e precisa valer no Oracle e no SQLite: agrupa-se só coluna de texto ou número (nunca CLOB),
 * listas JSON guardadas em texto (users.tipos_cadastro, inscricoes.cotas) são contadas com LIKE, e mês ou dia sai de
 * uma expressão por banco, sem bind no GROUP BY (o Oracle trata binds iguais como expressões diferentes).
 *
 * @phpstan-type Fatia array{valor: string, rotulo: string, total: int, percentual: float}
 * @phpstan-type Distribuicao array{itens: list<Fatia>, respostas: int, semResposta: int}
 * @phpstan-type Faixa array{rotulo: string, total: int, percentual: float}
 */
final class ConsultasDoPainel
{
    /** Abaixo disso o recorte por território não mostra percentual: com poucas pessoas, o número aponta alguém. */
    public const int BASE_MINIMA = 5;

    public function __construct(public readonly FiltrosDoPainel $filtros) {}

    /** Pessoas do portal (users + pessoas_fisicas), com os filtros de público, território, edital e período do cadastro. */
    public function pessoas(): Consulta
    {
        $filtros = $this->filtros;

        return User::query()
            ->doPortal()
            ->join('pessoas_fisicas', 'pessoas_fisicas.user_id', '=', 'users.id')
            ->when($filtros->publico, fn (Builder $q, TipoCadastro $tipo) => $q->where('users.tipos_cadastro', 'like', self::naLista($tipo->value)))
            ->when($filtros->territorio, fn (Builder $q, string $codigo) => $q->where('pessoas_fisicas.territorio', $codigo))
            ->when($filtros->editalId, fn (Builder $q, int $edital) => $q->whereExists(
                fn (Consulta $inscricao) => $inscricao->selectRaw('1')
                    ->from('inscricoes')
                    ->whereColumn('inscricoes.user_id', 'users.id')
                    ->where('inscricoes.edital_id', $edital)
                    ->where('inscricoes.situacao', SituacaoInscricao::Finalizada->value),
            ))
            ->when($filtros->de, fn (Builder $q) => $q->where('users.profile_completed_at', '>=', $filtros->inicio()))
            ->when($filtros->ate, fn (Builder $q) => $q->where('users.profile_completed_at', '<', $filtros->fim()))
            ->toBase();
    }

    /** Organizações, com os filtros de edital e período do cadastro (elas não têm território nem tipo de cadastro). */
    public function organizacoes(): Consulta
    {
        $filtros = $this->filtros;

        return Organizacao::query()
            ->when($filtros->editalId, fn (Builder $q, int $edital) => $q->whereExists(
                fn (Consulta $inscricao) => $inscricao->selectRaw('1')
                    ->from('inscricoes')
                    ->whereColumn('inscricoes.organizacao_id', 'organizacoes.id')
                    ->where('inscricoes.edital_id', $edital)
                    ->where('inscricoes.situacao', SituacaoInscricao::Finalizada->value),
            ))
            ->when($filtros->de, fn (Builder $q) => $q->where('organizacoes.created_at', '>=', $filtros->inicio()))
            ->when($filtros->ate, fn (Builder $q) => $q->where('organizacoes.created_at', '<', $filtros->fim()))
            ->toBase();
    }

    /**
     * Inscrições de editais não excluídos, com os filtros de edital, público e território de quem se inscreveu.
     * O período depende da situação: veja enviadas() e rascunhos().
     */
    public function inscricoes(): Consulta
    {
        $filtros = $this->filtros;

        return Inscricao::query()
            ->join('editais', 'editais.id', '=', 'inscricoes.edital_id')
            ->join('users', 'users.id', '=', 'inscricoes.user_id')
            ->join('pessoas_fisicas', 'pessoas_fisicas.user_id', '=', 'inscricoes.user_id')
            ->whereNull('editais.deleted_at')
            ->when($filtros->editalId, fn (Builder $q, int $edital) => $q->where('inscricoes.edital_id', $edital))
            ->when($filtros->publico, fn (Builder $q, TipoCadastro $tipo) => $q->where('users.tipos_cadastro', 'like', self::naLista($tipo->value)))
            ->when($filtros->territorio, fn (Builder $q, string $codigo) => $q->where('pessoas_fisicas.territorio', $codigo))
            ->toBase();
    }

    /** Inscrições enviadas (finalizadas), pelo dia do envio. */
    public function enviadas(): Consulta
    {
        $filtros = $this->filtros;

        return $this->inscricoes()
            ->where('inscricoes.situacao', SituacaoInscricao::Finalizada->value)
            ->when($filtros->de, fn (Consulta $q) => $q->where('inscricoes.finalizada_em', '>=', $filtros->inicio()))
            ->when($filtros->ate, fn (Consulta $q) => $q->where('inscricoes.finalizada_em', '<', $filtros->fim()));
    }

    /** Inscrições em rascunho (inclusive as reabertas), pelo dia em que começaram. */
    public function rascunhos(): Consulta
    {
        $filtros = $this->filtros;

        return $this->inscricoes()
            ->where('inscricoes.situacao', SituacaoInscricao::Rascunho->value)
            ->when($filtros->de, fn (Consulta $q) => $q->where('inscricoes.created_at', '>=', $filtros->inicio()))
            ->when($filtros->ate, fn (Consulta $q) => $q->where('inscricoes.created_at', '<', $filtros->fim()));
    }

    /**
     * Editais não excluídos, com os filtros de edital e período (o de inscrições cruza o intervalo).
     *
     * @return Builder<Edital>
     */
    public function editais(): Builder
    {
        $filtros = $this->filtros;

        return Edital::query()
            ->when($filtros->editalId, fn (Builder $q, int $edital) => $q->whereKey($edital))
            ->when($filtros->de, fn (Builder $q, CarbonImmutable $de) => $q->where('inscricoes_fim', '>=', $de->toDateString()))
            ->when($filtros->ate, fn (Builder $q, CarbonImmutable $ate) => $q->where('inscricoes_inicio', '<', $ate->addDay()->toDateString()));
    }

    /**
     * Quantos há de cada valor da coluna; nulo vira ''.
     *
     * @return array<string, int>
     */
    public function totaisPor(Consulta $consulta, string $coluna): array
    {
        return (clone $consulta)
            ->select("{$coluna} as valor")
            ->selectRaw('count(*) as total')
            ->groupBy($coluna)
            ->get()
            ->mapWithKeys(fn (object $linha): array => [(string) $linha->valor => (int) $linha->total])
            ->all();
    }

    /**
     * Quantos há de cada caso do enum, na ordem do enum e com os zeros. O percentual é sobre quem respondeu; vazio ou
     * valor antigo fora do enum conta em semResposta.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return Distribuicao
     */
    public function distribuicao(Consulta $consulta, string $coluna, string $enum): array
    {
        $totais = $this->totaisPor($consulta, $coluna);
        $itens = array_map(fn (BackedEnum $caso): array => [
            'valor' => (string) $caso->value,
            'rotulo' => $caso->rotulo(),
            'total' => $totais[(string) $caso->value] ?? 0,
        ], $enum::cases());
        $respostas = array_sum(array_column($itens, 'total'));

        return [
            'itens' => array_map(fn (array $item): array => [...$item, 'percentual' => self::percentual($item['total'], $respostas)], $itens),
            'respostas' => $respostas,
            'semResposta' => array_sum($totais) - $respostas,
        ];
    }

    /**
     * Totais por mês (aaaa-mm), de $primeiroMes em diante, com os meses vazios.
     *
     * @return list<array{mes: string, total: int}>
     */
    public function porMes(Consulta $consulta, string $coluna, CarbonImmutable $primeiroMes, int $meses): array
    {
        $expressao = $this->mesDe($coluna);
        $totais = (clone $consulta)
            ->where($coluna, '>=', $primeiroMes->toDateTimeString())
            ->where($coluna, '<', $primeiroMes->addMonths($meses)->toDateTimeString())
            ->selectRaw("{$expressao} as mes")
            ->selectRaw('count(*) as total')
            ->groupByRaw($expressao)
            ->pluck('total', 'mes');

        return array_map(function (int $indice) use ($primeiroMes, $totais): array {
            $mes = $primeiroMes->addMonths($indice)->format('Y-m');

            return ['mes' => $mes, 'total' => (int) ($totais[$mes] ?? 0)];
        }, range(0, $meses - 1));
    }

    /**
     * Totais por dia (aaaa-mm-dd) entre os dois dias, inclusive; só os dias com algum.
     *
     * @return array<string, int>
     */
    public function porDia(Consulta $consulta, string $coluna, CarbonImmutable $primeiroDia, CarbonImmutable $ultimoDia): array
    {
        $expressao = $this->diaDe($coluna);

        return (clone $consulta)
            ->where($coluna, '>=', $primeiroDia->toDateTimeString())
            ->where($coluna, '<', $ultimoDia->addDay()->toDateTimeString())
            ->selectRaw("{$expressao} as dia")
            ->selectRaw('count(*) as total')
            ->groupByRaw($expressao)
            ->pluck('total', 'dia')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** Mês (aaaa-mm) da coluna no SQL do banco em uso. A coluna vem do código, nunca de quem usa a tela. */
    public function mesDe(string $coluna): string
    {
        return $this->ehOracle() ? "TO_CHAR({$coluna}, 'YYYY-MM')" : "strftime('%Y-%m', {$coluna})";
    }

    /** Dia (aaaa-mm-dd) da coluna no SQL do banco em uso. A coluna vem do código, nunca de quem usa a tela. */
    public function diaDe(string $coluna): string
    {
        return $this->ehOracle() ? "TO_CHAR({$coluna}, 'YYYY-MM-DD')" : "strftime('%Y-%m-%d', {$coluna})";
    }

    /**
     * Recortes do perfil que o painel compara (cadastro × inscrição, territórios), cada um como condição em pessoas_fisicas.
     *
     * @return array<string, array{rotulo: string, condicao: Closure(Consulta): mixed}>
     */
    public static function recortesDoPerfil(): array
    {
        return [
            'mulheres' => [
                'rotulo' => 'Mulheres',
                'condicao' => fn (Consulta $q) => $q->whereIn('pessoas_fisicas.identidade_genero', ['mulher_cis', 'mulher_trans']),
            ],
            'negras' => [
                'rotulo' => 'Pessoas negras',
                'condicao' => fn (Consulta $q) => $q->whereIn('pessoas_fisicas.raca_cor', ['preta', 'parda']),
            ],
            'indigenas' => [
                'rotulo' => 'Pessoas indígenas',
                'condicao' => fn (Consulta $q) => $q->where('pessoas_fisicas.raca_cor', 'indigena'),
            ],
            'pcd' => [
                'rotulo' => 'Pessoas com deficiência',
                'condicao' => fn (Consulta $q) => $q->where('pessoas_fisicas.pcd', true),
            ],
            'trans' => [
                'rotulo' => 'Pessoas trans, travestis e não binárias',
                'condicao' => fn (Consulta $q) => $q->whereIn('pessoas_fisicas.identidade_genero', ['mulher_trans', 'homem_trans', 'travesti', 'nao_binaria']),
            ],
            'renda' => [
                'rotulo' => 'Renda de até 1 salário mínimo',
                'condicao' => fn (Consulta $q) => $q->whereIn('pessoas_fisicas.renda', ['nenhuma', 'ate_1']),
            ],
            'povos' => [
                'rotulo' => 'Povos e comunidades tradicionais',
                'condicao' => fn (Consulta $q) => $q->whereNotNull('pessoas_fisicas.povo_tradicional')->where('pessoas_fisicas.povo_tradicional', '<>', 'nao_pertence'),
            ],
        ];
    }

    /**
     * Rótulo de cada território, na ordem das Prefeituras-Bairro (com o número romano) e "Fora de Salvador" no fim.
     *
     * @return array<string, string>
     */
    public static function territorios(): array
    {
        return PrefeituraBairro::query()
            ->orderBy('numero')
            ->get(['codigo', 'nome', 'numero'])
            ->mapWithKeys(fn (PrefeituraBairro $prefeitura): array => [$prefeitura->codigo => "{$prefeitura->numeroRomano()} – {$prefeitura->nome}"])
            ->put(PrefeituraBairro::FORA_DE_SALVADOR, (string) PrefeituraBairro::rotuloDoCodigo(PrefeituraBairro::FORA_DE_SALVADOR))
            ->all();
    }

    /**
     * Totais por faixa de um número (anos, idade). Cada faixa é [mínimo, máximo], com máximo nulo para "ou mais";
     * o que não cabe em nenhuma (vazio) fica de fora, e o percentual é sobre o que coube.
     *
     * @param  array<int|string, int>  $totaisPorNumero
     * @param  array<string, array{0: int, 1: int|null}>  $faixas
     * @return list<Faixa>
     */
    public static function emFaixas(array $totaisPorNumero, array $faixas): array
    {
        $porFaixa = array_fill_keys(array_keys($faixas), 0);

        foreach ($totaisPorNumero as $numero => $total) {
            if (! is_numeric($numero)) {
                continue;
            }
            foreach ($faixas as $rotulo => [$minimo, $maximo]) {
                if ((int) $numero >= $minimo && ($maximo === null || (int) $numero <= $maximo)) {
                    $porFaixa[$rotulo] += $total;
                    break;
                }
            }
        }

        $soma = array_sum($porFaixa);

        return array_map(
            fn (string $rotulo, int $total): array => ['rotulo' => $rotulo, 'total' => $total, 'percentual' => self::percentual($total, $soma)],
            array_keys($porFaixa),
            array_values($porFaixa),
        );
    }

    /** Padrão do LIKE que acha um valor numa lista JSON guardada em texto, ex.: ["proponente","patrocinador"]. */
    public static function naLista(string $valor): string
    {
        return '%"'.$valor.'"%';
    }

    /** Percentual com uma casa; zero quando não há base. */
    public static function percentual(int $parte, int $todo): float
    {
        return $todo > 0 ? round($parte * 100 / $todo, 1) : 0.0;
    }

    private function ehOracle(): bool
    {
        return DB::connection()->getDriverName() === 'oracle';
    }
}
