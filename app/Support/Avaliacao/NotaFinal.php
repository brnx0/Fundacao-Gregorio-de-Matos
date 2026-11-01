<?php

namespace App\Support\Avaliacao;

use App\Models\InscricaoAvaliacaoNota;

/** Nota final da avaliação: soma de nota × peso, com o peso copiado na nota. */
final class NotaFinal
{
    /** Peso gravado na nota quando o critério não tinha peso. */
    public const string PESO_PADRAO = '1.00';

    /**
     * Soma em centésimos inteiros (nota × peso tem 4 casas) e arredonda meio para cima em 2 casas.
     *
     * @param  iterable<InscricaoAvaliacaoNota>  $notas
     */
    public static function de(iterable $notas): string
    {
        $totalEmDecimosDeMilesimo = 0;

        foreach ($notas as $nota) {
            $totalEmDecimosDeMilesimo += self::emCentesimos((string) $nota->nota) * self::emCentesimos((string) ($nota->peso ?? self::PESO_PADRAO));
        }

        $centesimos = intdiv($totalEmDecimosDeMilesimo + 50, 100);

        return intdiv($centesimos, 100).'.'.str_pad((string) ($centesimos % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function emCentesimos(string $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
