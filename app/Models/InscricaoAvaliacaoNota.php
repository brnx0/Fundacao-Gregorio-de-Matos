<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota de um critério na avaliação; peso e pontuação máxima são a cópia do edital no momento da nota.
 */
class InscricaoAvaliacaoNota extends Model
{
    protected $table = 'inscricao_avaliacao_notas';

    protected $fillable = ['inscricao_avaliacao_id', 'edital_criterio_id', 'nota', 'peso', 'pontos_maximos'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'nota' => 'decimal:2',
            'peso' => 'decimal:2',
            'pontos_maximos' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<InscricaoAvaliacao, $this> */
    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(InscricaoAvaliacao::class, 'inscricao_avaliacao_id');
    }

    /** @return BelongsTo<EditalCriterio, $this> */
    public function criterio(): BelongsTo
    {
        return $this->belongsTo(EditalCriterio::class, 'edital_criterio_id');
    }
}
