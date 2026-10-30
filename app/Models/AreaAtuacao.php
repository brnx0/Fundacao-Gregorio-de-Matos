<?php

namespace App\Models;

use Database\Factories\AreaAtuacaoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Área de atuação cultural (ex.: Audiovisual, Teatro), cadastrada na retaguarda (Parametrização). Não se exclui, só se
 * desativa: a lista vai ser escolhida nos editais e nas inscrições.
 */
class AreaAtuacao extends Model
{
    /** @use HasFactory<AreaAtuacaoFactory> */
    use HasFactory;

    /** A área genérica do levantamento: nas listas, vem por último. */
    private const string OUTROS = 'Outros';

    protected $table = 'areas_atuacao';

    protected $fillable = ['nome', 'ativo'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    /**
     * Opções do cadastro da pessoa física: as ativas e as que ela já marcou (mesmo desativadas depois).
     *
     * @param  list<int>  $marcadas
     * @return list<array{valor: int, rotulo: string}>
     */
    public static function opcoesDoPortal(array $marcadas = []): array
    {
        $areas = self::query()
            ->where(fn (Builder $q) => $q->where('ativo', true)->orWhereIn('id', $marcadas))
            ->get(['id', 'nome']);

        return self::emOrdem($areas)
            ->map(fn (self $area): array => ['valor' => (int) $area->id, 'rotulo' => $area->nome])
            ->all();
    }

    /**
     * @param  list<int>  $marcadas
     * @return list<int>
     */
    public static function idsAceitos(array $marcadas = []): array
    {
        return array_column(self::opcoesDoPortal($marcadas), 'valor');
    }

    /**
     * Na ordem das listas: pelo nome, sem diferenciar acento nem caixa (o ORDER BY do Oracle compara bytes), com
     * "Outros" no fim.
     *
     * @param  Collection<int, self>  $areas
     * @return Collection<int, self>
     */
    public static function emOrdem(Collection $areas): Collection
    {
        return $areas
            ->sortBy(fn (self $area): string => ($area->nome === self::OUTROS ? '1' : '0').Str::lower(Str::ascii($area->nome)))
            ->values();
    }
}
