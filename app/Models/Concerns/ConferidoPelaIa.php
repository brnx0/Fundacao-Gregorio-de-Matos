<?php

namespace App\Models\Concerns;

use App\Enums\SituacaoConferencia;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Arquivo conferido por IA num processo à parte (colunas situacao, execucao e iniciada_em).
 *
 * @property SituacaoConferencia $situacao
 * @property CarbonInterface|null $iniciada_em
 */
trait ConferidoPelaIa
{
    /** Depois disso, uma conferência que não terminou é mostrada como não conferida (o processo morreu). */
    public const int MINUTOS_LIMITE = 5;

    /** A situação para a tela: "conferindo" há mais de 5 minutos é mostrada como não conferida. */
    public function situacaoExibida(?CarbonInterface $agora = null): SituacaoConferencia
    {
        if ($this->situacao === SituacaoConferencia::Conferindo
            && $this->iniciada_em->lt(($agora ?? now())->copy()->subMinutes(self::MINUTOS_LIMITE))) {
            return SituacaoConferencia::NaoConferido;
        }

        return $this->situacao;
    }

    /** Só o arquivo ainda na conferência $execucao. */
    public function scopeDaExecucao(Builder $consulta, string $execucao): void
    {
        $consulta->where('execucao', $execucao)
            ->where('situacao', SituacaoConferencia::Conferindo->value);
    }
}
