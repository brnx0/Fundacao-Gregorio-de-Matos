<?php

namespace App\Models;

use App\Enums\SituacaoEdital;
use App\Models\Concerns\UsaDiaCivil;
use Carbon\CarbonInterface;
use Database\Factories\EditalChamadaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Chamada do edital (o edital comum tem uma, alguns têm várias): a Inscrição (`inicio`/`fim`), a Validação e a Homologação. */
#[Fillable(['nome', 'inicio', 'fim', 'validacao_inicio', 'validacao_fim', 'homologacao_inicio', 'homologacao_fim', 'ordem'])]
class EditalChamada extends Model
{
    /** @use HasFactory<EditalChamadaFactory> */
    use HasFactory;

    use UsaDiaCivil;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio' => 'date',
            'fim' => 'date',
            'validacao_inicio' => 'date',
            'validacao_fim' => 'date',
            'homologacao_inicio' => 'date',
            'homologacao_fim' => 'date',
            'ordem' => 'integer',
        ];
    }

    protected function inicio(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function fim(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function validacaoInicio(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function validacaoFim(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function homologacaoInicio(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function homologacaoFim(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    /** Mesma regra do dia civil de Edital::situacao(); nula sem as duas datas. */
    public function situacao(?CarbonInterface $agora = null): ?SituacaoEdital
    {
        if ($this->inicio === null || $this->fim === null) {
            return null;
        }

        $hoje = self::hoje($agora)->toDateString();

        return match (true) {
            $hoje < $this->inicio->toDateString() => SituacaoEdital::EmBreve,
            $hoje > $this->fim->toDateString() => SituacaoEdital::Encerrado,
            default => SituacaoEdital::Aberto,
        };
    }

    /** As duas datas da homologação preenchidas. */
    public function temPeriodoDeHomologacao(): bool
    {
        return $this->homologacao_inicio !== null && $this->homologacao_fim !== null;
    }

    /** Hoje entre o início e o fim da homologação, inclusive (dias inteiros, ver UsaDiaCivil); falso sem as duas datas. */
    public function naHomologacao(?CarbonInterface $agora = null): bool
    {
        if (! $this->temPeriodoDeHomologacao()) {
            return false;
        }

        $hoje = self::hoje($agora)->toDateString();

        return $hoje >= $this->homologacao_inicio->toDateString() && $hoje <= $this->homologacao_fim->toDateString();
    }

    /** @return BelongsTo<Edital, $this> */
    public function edital(): BelongsTo
    {
        return $this->belongsTo(Edital::class);
    }
}
