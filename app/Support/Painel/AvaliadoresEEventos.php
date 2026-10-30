<?php

namespace App\Support\Painel;

use App\Models\Avaliador;
use App\Models\Evento;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Capacidade de avaliar e agenda: avaliadores (com a faixa etária) e quem quer avaliar, e os eventos publicados.
 * Avaliadores não passam pelos filtros: são o quadro atual. Eventos seguem o período, pelo dia de realização.
 *
 * @phpstan-import-type Faixa from ConsultasDoPainel
 */
final class AvaliadoresEEventos
{
    /** Idade em anos completos: [mínimo, máximo], máximo nulo para "ou mais". */
    private const array FAIXAS_DE_IDADE = [
        'Até 29 anos' => [0, 29],
        '30 a 39 anos' => [30, 39],
        '40 a 49 anos' => [40, 49],
        '50 a 59 anos' => [50, 59],
        '60 anos ou mais' => [60, null],
    ];

    /** "Próximos" eventos: de hoje até estes dias à frente. */
    private const int DIAS_PROXIMOS = 30;

    /**
     * @return array{
     *     avaliadores: array{ativos: int, inativos: int, idades: list<Faixa>, interessadas: int, experientes: int},
     *     eventos: array{publicados: int, proximos: int, realizados: int, porMes: list<array{mes: string, total: int}>},
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $hoje = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $pessoas = $consultas->pessoas();

        $porIdade = Avaliador::query()
            ->where('ativo', true)
            ->pluck('data_nascimento')
            ->countBy(fn (DateTimeInterface $nascimento): int => (int) CarbonImmutable::instance($nascimento)->diffInYears($hoje))
            ->all();
        $ativos = array_sum($porIdade);

        $filtros = $consultas->filtros;
        $eventos = Evento::query()
            ->where('publicado', true)
            ->when($filtros->de, fn (Builder $q, CarbonImmutable $de) => $q->where('data_realizacao', '>=', $de->toDateString()))
            ->when($filtros->ate, fn (Builder $q, CarbonImmutable $ate) => $q->where('data_realizacao', '<', $ate->addDay()->toDateString()));

        return [
            'avaliadores' => [
                'ativos' => $ativos,
                'inativos' => Avaliador::query()->where('ativo', false)->count(),
                'idades' => ConsultasDoPainel::emFaixas($porIdade, self::FAIXAS_DE_IDADE),
                'interessadas' => (clone $pessoas)->where('pessoas_fisicas.interesse_avaliador', true)->count(),
                'experientes' => (clone $pessoas)->where('pessoas_fisicas.ja_foi_avaliador', true)->count(),
            ],
            'eventos' => [
                'publicados' => (clone $eventos)->count(),
                'proximos' => (clone $eventos)
                    ->where('data_realizacao', '>=', $hoje->toDateString())
                    ->where('data_realizacao', '<', $hoje->addDays(self::DIAS_PROXIMOS + 1)->toDateString())
                    ->count(),
                'realizados' => (clone $eventos)->where('data_realizacao', '<', $hoje->toDateString())->count(),
                // Seis meses para trás e seis para a frente: o que aconteceu e o que vem.
                'porMes' => $consultas->porMes((clone $eventos)->toBase(), 'eventos.data_realizacao', $hoje->startOfMonth()->subMonths(6), 12),
            ],
        ];
    }
}
