<?php

namespace App\Support\Painel;

use App\Enums\SituacaoInscricao;
use Illuminate\Database\Query\Builder as Consulta;

/**
 * Evolução no tempo e recorte por território: novos cadastros por mês (com o acumulado), o ritmo diário das
 * inscrições e o perfil de cada Prefeitura-Bairro.
 */
final class Evolucao
{
    /** Sem período no filtro, o gráfico mensal mostra os últimos 24 meses; com período, no máximo 36. */
    private const int MESES_PADRAO = 24;

    private const int MESES_MAXIMOS = 36;

    /** O calendário mostra um ano de envios, até o último dia do filtro (ou hoje). */
    private const int DIAS_DO_RITMO = 365;

    /**
     * @return array{
     *     meses: list<array{mes: string, proponentes: int, patrocinadores: int, ambos: int, semTipo: int, total: int, acumulado: int}>,
     *     ritmo: array{inicio: string, fim: string, dias: array<string, int>, total: int, maximo: int},
     *     territorios: array{
     *         colunas: list<array{valor: string, rotulo: string}>,
     *         linhas: list<array{codigo: string, rotulo: string, base: int, valores: list<float|null>}>,
     *     },
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        return [
            'meses' => self::cadastrosPorMes($consultas),
            'ritmo' => self::ritmo($consultas),
            'territorios' => self::territorios($consultas),
        ];
    }

    /**
     * Novos cadastros (perfil enviado) por mês e pelo tipo escolhido, com o total acumulado desde o início.
     *
     * @return list<array{mes: string, proponentes: int, patrocinadores: int, ambos: int, semTipo: int, total: int, acumulado: int}>
     */
    private static function cadastrosPorMes(ConsultasDoPainel $consultas): array
    {
        $filtros = $consultas->filtros;
        $ultimoMes = $filtros->ultimoDia()->startOfMonth();
        $primeiroMes = $filtros->de?->startOfMonth() ?? $ultimoMes->subMonths(self::MESES_PADRAO - 1);
        $primeiroMes = $primeiroMes->max($ultimoMes->subMonths(self::MESES_MAXIMOS - 1))->min($ultimoMes);
        $meses = (int) round($primeiroMes->diffInMonths($ultimoMes)) + 1;

        $coluna = 'users.profile_completed_at';
        $expressao = $consultas->mesDe($coluna);
        $pessoas = $consultas->pessoas();
        $linhas = (clone $pessoas)
            ->where($coluna, '>=', $primeiroMes->toDateTimeString())
            ->where($coluna, '<', $ultimoMes->addMonth()->toDateTimeString())
            ->selectRaw("{$expressao} as mes")
            ->addSelect('users.tipos_cadastro as tipos')
            ->selectRaw('count(*) as total')
            ->groupByRaw($expressao)
            ->groupBy('users.tipos_cadastro')
            ->get();

        $porMes = [];
        foreach ($linhas as $linha) {
            // tipos_cadastro guarda o JSON dos valores, ex.: ["proponente","patrocinador"]; nulo é cadastro sem tipo.
            $proponente = str_contains((string) $linha->tipos, '"proponente"');
            $patrocinador = str_contains((string) $linha->tipos, '"patrocinador"');
            $grupo = match (true) {
                $proponente && $patrocinador => 'ambos',
                $proponente => 'proponentes',
                $patrocinador => 'patrocinadores',
                default => 'semTipo',
            };
            $porMes[(string) $linha->mes][$grupo] = ($porMes[(string) $linha->mes][$grupo] ?? 0) + (int) $linha->total;
        }

        $acumulado = (clone $pessoas)->where($coluna, '<', $primeiroMes->toDateTimeString())->count();

        return array_map(function (int $indice) use ($primeiroMes, $porMes, &$acumulado): array {
            $mes = $primeiroMes->addMonths($indice)->format('Y-m');
            $grupos = ['proponentes' => 0, 'patrocinadores' => 0, 'ambos' => 0, 'semTipo' => 0, ...($porMes[$mes] ?? [])];
            $total = array_sum($grupos);
            $acumulado += $total;

            return ['mes' => $mes, ...$grupos, 'total' => $total, 'acumulado' => $acumulado];
        }, range(0, $meses - 1));
    }

    /**
     * Inscrições enviadas por dia, para o calendário (só os dias com envio).
     *
     * @return array{inicio: string, fim: string, dias: array<string, int>, total: int, maximo: int}
     */
    private static function ritmo(ConsultasDoPainel $consultas): array
    {
        $fim = $consultas->filtros->ultimoDia();
        $inicio = $fim->subDays(self::DIAS_DO_RITMO - 1);
        $dias = $consultas->porDia($consultas->enviadas(), 'inscricoes.finalizada_em', $inicio, $fim);

        return [
            'inicio' => $inicio->toDateString(),
            'fim' => $fim->toDateString(),
            'dias' => $dias,
            'total' => array_sum($dias),
            'maximo' => $dias === [] ? 0 : max($dias),
        ];
    }

    /**
     * Perfil de cada território: percentual de cada recorte sobre as pessoas dali. Com menos de
     * ConsultasDoPainel::BASE_MINIMA pessoas, o percentual não aparece (nulo).
     *
     * @return array{
     *     colunas: list<array{valor: string, rotulo: string}>,
     *     linhas: list<array{codigo: string, rotulo: string, base: int, valores: list<float|null>}>,
     * }
     */
    private static function territorios(ConsultasDoPainel $consultas): array
    {
        $pessoas = $consultas->pessoas();
        $bases = $consultas->totaisPor($pessoas, 'pessoas_fisicas.territorio');
        $recortes = [
            ...ConsultasDoPainel::recortesDoPerfil(),
            'cultura' => [
                'rotulo' => 'Cultura como principal renda',
                'condicao' => fn (Consulta $q) => $q->where('pessoas_fisicas.cultura_principal_renda', true),
            ],
            'inscritas' => [
                'rotulo' => 'Enviaram inscrição',
                'condicao' => fn (Consulta $q) => $q->whereExists(
                    fn (Consulta $inscricao) => $inscricao->selectRaw('1')
                        ->from('inscricoes')
                        ->whereColumn('inscricoes.user_id', 'users.id')
                        ->where('inscricoes.situacao', SituacaoInscricao::Finalizada->value),
                ),
            ],
        ];
        $porRecorte = array_map(
            fn (array $recorte): array => $consultas->totaisPor(($recorte['condicao'])(clone $pessoas), 'pessoas_fisicas.territorio'),
            $recortes,
        );

        $linhas = [];
        foreach (ConsultasDoPainel::territorios() as $codigo => $rotulo) {
            $base = $bases[$codigo] ?? 0;
            $linhas[] = [
                'codigo' => $codigo,
                'rotulo' => $rotulo,
                'base' => $base,
                'valores' => array_values(array_map(
                    fn (array $totais): ?float => $base < ConsultasDoPainel::BASE_MINIMA ? null : ConsultasDoPainel::percentual($totais[$codigo] ?? 0, $base),
                    $porRecorte,
                )),
            ];
        }

        return [
            'colunas' => array_map(
                fn (string $valor, array $recorte): array => ['valor' => $valor, 'rotulo' => $recorte['rotulo']],
                array_keys($recortes),
                array_values($recortes),
            ),
            'linhas' => $linhas,
        ];
    }
}
