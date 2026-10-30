<?php

namespace App\Support\Painel;

use App\Enums\SituacaoEdital;
use App\Enums\SituacaoInscricao;
use App\Enums\TipoCadastro;
use App\Models\Avaliador;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Consulta;

/**
 * Primeira faixa do Painel: os totais com a série dos últimos 12 meses e o funil do cadastro.
 *
 * @phpstan-type Indicador array{total: int, serie: list<int>}
 * @phpstan-type Etapa array{rotulo: string, total: int, percentual: float}
 */
final class VisaoGeral
{
    /**
     * @return array{
     *     meses: list<string>,
     *     indicadores: array{pessoas: Indicador, proponentes: Indicador, patrocinadores: Indicador, organizacoes: Indicador, inscricoes: Indicador},
     *     editais: array{abertos: int, emBreve: int},
     *     investimento: array{centavos: int, vagas: int},
     *     avaliadores: array{ativos: int, total: int},
     *     funil: list<Etapa>,
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $primeiroMes = $consultas->filtros->ultimoDia()->startOfMonth()->subMonths(11);
        $pessoas = $consultas->pessoas();
        $doTipo = fn (TipoCadastro $tipo): Consulta => (clone $pessoas)->where('users.tipos_cadastro', 'like', ConsultasDoPainel::naLista($tipo->value));
        // Abertos e em breve contam como no portal (Edital::contarAbertos): só os visíveis.
        $visiveis = $consultas->editais()->visiveis();
        $oferta = Editais::oferta($consultas->editais()->publicados()->select('editais.id'));

        return [
            'meses' => array_map(fn (int $indice): string => $primeiroMes->addMonths($indice)->format('Y-m'), range(0, 11)),
            'indicadores' => [
                'pessoas' => self::indicador($consultas, $pessoas, 'users.profile_completed_at', $primeiroMes),
                'proponentes' => self::indicador($consultas, $doTipo(TipoCadastro::Proponente), 'users.profile_completed_at', $primeiroMes),
                'patrocinadores' => self::indicador($consultas, $doTipo(TipoCadastro::Patrocinador), 'users.profile_completed_at', $primeiroMes),
                'organizacoes' => self::indicador($consultas, $consultas->organizacoes(), 'organizacoes.created_at', $primeiroMes),
                'inscricoes' => self::indicador($consultas, $consultas->enviadas(), 'inscricoes.finalizada_em', $primeiroMes),
            ],
            'editais' => [
                'abertos' => (clone $visiveis)->naSituacao(SituacaoEdital::Aberto)->count(),
                'emBreve' => (clone $visiveis)->naSituacao(SituacaoEdital::EmBreve)->count(),
            ],
            'investimento' => [
                'centavos' => array_sum(array_column($oferta, 'centavos')),
                'vagas' => array_sum(array_column($oferta, 'vagas')),
            ],
            'avaliadores' => [
                'ativos' => Avaliador::query()->where('ativo', true)->count(),
                'total' => Avaliador::query()->count(),
            ],
            'funil' => self::funil($consultas->filtros),
        ];
    }

    /** @return Indicador */
    private static function indicador(ConsultasDoPainel $consultas, Consulta $consulta, string $coluna, CarbonImmutable $primeiroMes): array
    {
        return [
            'total' => (clone $consulta)->count(),
            'serie' => array_column($consultas->porMes($consulta, $coluna, $primeiroMes, 12), 'total'),
        ];
    }

    /**
     * Do primeiro acesso pelo gov.br à inscrição enviada, sobre quem entrou no período. Vale só o filtro de período:
     * antes de enviar o perfil a pessoa ainda não tem tipo de cadastro nem território.
     *
     * @return list<Etapa>
     */
    private static function funil(FiltrosDoPainel $filtros): array
    {
        $entraram = User::query()
            ->whereDoesntHave('papeis')
            ->when($filtros->de, fn (Builder $q) => $q->where('users.created_at', '>=', $filtros->inicio()))
            ->when($filtros->ate, fn (Builder $q) => $q->where('users.created_at', '<', $filtros->fim()));
        $comPerfil = (clone $entraram)->whereHas('pessoaFisica');

        $etapas = [
            'Entraram pelo gov.br' => (clone $entraram)->count(),
            'Enviaram o perfil' => (clone $comPerfil)->count(),
            'Escolheram o tipo de cadastro' => (clone $comPerfil)->whereNotNull('users.tipos_cadastro')->count(),
            'Enviaram inscrição' => (clone $comPerfil)->whereExists(
                fn (Consulta $inscricao) => $inscricao->selectRaw('1')
                    ->from('inscricoes')
                    ->whereColumn('inscricoes.user_id', 'users.id')
                    ->where('inscricoes.situacao', SituacaoInscricao::Finalizada->value),
            )->count(),
        ];
        $base = $etapas['Entraram pelo gov.br'];

        return array_map(
            fn (string $rotulo, int $total): array => ['rotulo' => $rotulo, 'total' => $total, 'percentual' => ConsultasDoPainel::percentual($total, $base)],
            array_keys($etapas),
            array_values($etapas),
        );
    }
}
