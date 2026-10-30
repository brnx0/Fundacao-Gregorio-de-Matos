<?php

namespace App\Models;

use App\Enums\MarcacaoDoItem;
use Database\Factories\InscricaoConferenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcação do gestor sobre um item da inscrição na conferência: um documento exigido ou uma pergunta do questionário
 * (um dos dois ids é preenchido), com a observação quando há problema.
 */
class InscricaoConferencia extends Model
{
    /** @use HasFactory<InscricaoConferenciaFactory> */
    use HasFactory;

    protected $table = 'inscricao_conferencias';

    protected $fillable = [
        'inscricao_id', 'edital_documento_id', 'edital_pergunta_id', 'situacao', 'observacao', 'user_id',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'situacao' => MarcacaoDoItem::class,
        ];
    }

    /** @return BelongsTo<Inscricao, $this> */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /** @return BelongsTo<EditalDocumento, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(EditalDocumento::class, 'edital_documento_id');
    }

    /** @return BelongsTo<EditalPergunta, $this> */
    public function pergunta(): BelongsTo
    {
        return $this->belongsTo(EditalPergunta::class, 'edital_pergunta_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
