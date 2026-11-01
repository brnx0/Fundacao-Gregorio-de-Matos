<?php

namespace App\Models;

use App\Enums\SituacaoConvite;
use Carbon\CarbonInterface;
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

    /** Depois disso, um convite que não terminou é dado como perdido (o processo morreu). */
    public const int MINUTOS_LIMITE = 15;

    public const string ERRO_SEM_TERMINO = 'O envio não terminou. Tente reenviar.';

    public const string ERRO_AO_INICIAR = 'Não foi possível iniciar o envio. Tente reenviar.';

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

    /** A situação do convite para a tela: "na fila" há mais de 15 minutos é mostrada como falha. Null sem convite. */
    public function situacaoDoConvite(?CarbonInterface $agora = null): ?SituacaoConvite
    {
        return $this->conviteSemTermino($agora) ? SituacaoConvite::Falhou : $this->convite_situacao;
    }

    /** O erro do convite para a tela (o gravado ou o de envio sem término). */
    public function erroDoConvite(?CarbonInterface $agora = null): ?string
    {
        return $this->conviteSemTermino($agora) ? self::ERRO_SEM_TERMINO : $this->convite_erro;
    }

    private function conviteSemTermino(?CarbonInterface $agora): bool
    {
        return $this->convite_situacao === SituacaoConvite::NaFila
            && $this->convite_iniciado_em !== null
            && $this->convite_iniciado_em->lt(($agora ?? now())->copy()->subMinutes(self::MINUTOS_LIMITE));
    }
}
