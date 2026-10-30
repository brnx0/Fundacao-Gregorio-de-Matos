<?php

namespace App\Models;

use App\Enums\DecisaoRecurso;
use Database\Factories\InscricaoRecursoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recurso do proponente contra a não homologação (um por inscrição), com a decisão do gestor quando julgado.
 */
class InscricaoRecurso extends Model
{
    /** @use HasFactory<InscricaoRecursoFactory> */
    use HasFactory;

    protected $table = 'inscricao_recursos';

    protected $fillable = [
        'inscricao_id', 'justificativa', 'enviado_em', 'decisao', 'parecer', 'julgado_por', 'julgado_em',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'enviado_em' => 'datetime',
            'julgado_em' => 'datetime',
            'decisao' => DecisaoRecurso::class,
        ];
    }

    /** @return BelongsTo<Inscricao, $this> */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /** @return HasMany<InscricaoRecursoDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(InscricaoRecursoDocumento::class);
    }

    /** @return BelongsTo<User, $this> */
    public function julgador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'julgado_por');
    }
}
