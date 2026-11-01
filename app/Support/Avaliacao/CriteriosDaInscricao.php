<?php

namespace App\Support\Avaliacao;

use App\Models\EditalCriterio;
use App\Models\Inscricao;
use Illuminate\Database\Eloquent\Collection;

/** Critérios que valem para a inscrição: os de todas as categorias mais os ligados à categoria dela. */
final class CriteriosDaInscricao
{
    /** @return Collection<int, EditalCriterio> */
    public static function para(Inscricao $inscricao): Collection
    {
        $categoriaId = $inscricao->edital_categoria_id;

        return EditalCriterio::query()
            ->where('edital_id', $inscricao->edital_id)
            ->where(function ($consulta) use ($categoriaId): void {
                $consulta->where('todas_categorias', true);

                if ($categoriaId !== null) {
                    $consulta->orWhereHas('categorias', fn ($categorias) => $categorias->where('edital_categorias.id', (int) $categoriaId));
                }
            })
            ->orderBy('ordem')
            ->orderBy('id')
            ->get();
    }
}
