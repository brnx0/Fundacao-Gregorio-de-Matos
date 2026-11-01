<?php

namespace App\Models;

use App\Enums\SituacaoConvite;
use Database\Factories\EditalAvaliadorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Avaliador escolhido para um edital: o andamento do convite por e-mail e as categorias que ele avalia.
 */
class EditalAvaliador extends Model
{
    /** @use HasFactory<EditalAvaliadorFactory> */
    use HasFactory;

    protected $table = 'edital_avaliadores';

    protected $fillable = [
        'edital_id', 'avaliador_id', 'convite_situacao', 'convite_erro',
        'convite_enviado_em', 'convite_iniciado_em', 'convite_execucao',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'convite_situacao' => SituacaoConvite::class,
            'convite_enviado_em' => 'datetime',
            'convite_iniciado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Edital, $this> */
    public function edital(): BelongsTo
    {
        return $this->belongsTo(Edital::class);
    }

    /** @return BelongsTo<Avaliador, $this> */
    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(Avaliador::class);
    }

    /** @return BelongsToMany<EditalCategoria, $this> */
    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(EditalCategoria::class, 'edital_avaliador_categoria');
    }
}
