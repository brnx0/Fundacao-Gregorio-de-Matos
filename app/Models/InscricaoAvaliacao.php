<?php

namespace App\Models;

use App\Enums\SituacaoAvaliacao;
use Database\Factories\InscricaoAvaliacaoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avaliação de uma inscrição homologada: um avaliador por inscrição, as notas por critério, a observação e a nota final.
 */
class InscricaoAvaliacao extends Model
{
    /** @use HasFactory<InscricaoAvaliacaoFactory> */
    use HasFactory;

    protected $table = 'inscricao_avaliacoes';

    protected $fillable = [
        'inscricao_id', 'avaliador_id', 'atribuida_por', 'atribuida_em', 'observacao', 'concluida_em', 'nota_final',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'atribuida_em' => 'datetime',
            'concluida_em' => 'datetime',
            'nota_final' => 'decimal:2',
        ];
    }

    /** Concluída; com nota ou observação, em andamento; senão não iniciada. */
    public function situacao(): SituacaoAvaliacao
    {
        if ($this->concluida_em !== null) {
            return SituacaoAvaliacao::Concluida;
        }

        if ($this->observacao !== null || $this->notas()->exists()) {
            return SituacaoAvaliacao::EmAndamento;
        }

        return SituacaoAvaliacao::NaoIniciada;
    }

    /** @return BelongsTo<Inscricao, $this> */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /** @return BelongsTo<Avaliador, $this> */
    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(Avaliador::class);
    }

    /** @return BelongsTo<User, $this> */
    public function atribuidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atribuida_por');
    }

    /** @return HasMany<InscricaoAvaliacaoNota, $this> */
    public function notas(): HasMany
    {
        return $this->hasMany(InscricaoAvaliacaoNota::class);
    }
}
