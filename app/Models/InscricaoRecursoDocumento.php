<?php

namespace App\Models;

use Database\Factories\InscricaoRecursoDocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo novo enviado no recurso para um documento marcado com problema.
 */
class InscricaoRecursoDocumento extends Model
{
    /** @use HasFactory<InscricaoRecursoDocumentoFactory> */
    use HasFactory;

    protected $table = 'inscricao_recurso_documentos';

    protected $fillable = [
        'inscricao_recurso_id', 'edital_documento_id', 'arquivo', 'arquivo_nome',
    ];

    /** @return BelongsTo<InscricaoRecurso, $this> */
    public function recurso(): BelongsTo
    {
        return $this->belongsTo(InscricaoRecurso::class, 'inscricao_recurso_id');
    }

    /** @return BelongsTo<EditalDocumento, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(EditalDocumento::class, 'edital_documento_id');
    }
}
