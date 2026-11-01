<?php

namespace App\Support\Edital;

use App\Models\Edital;
use App\Models\EditalChamada;

/** Os períodos de uma etapa do edital (homologação ou avaliação), chamada por chamada, como texto para a tela. */
final class PeriodosDoEdital
{
    /**
     * O período de cada chamada do edital ("01/12/2026 a 10/12/2026"), com o nome da chamada entre parênteses quando há
     * mais de uma; "Não cadastrado" na chamada sem as duas datas e no edital sem chamada. O prefixo é o das colunas da
     * chamada: 'homologacao' ou 'validacao' (a avaliação).
     *
     * @param  'homologacao'|'validacao'  $prefixo
     * @return list<string>
     */
    public static function formatar(Edital $edital, string $prefixo): array
    {
        $chamadas = $edital->chamadas;

        if ($chamadas->isEmpty()) {
            return ['Não cadastrado'];
        }

        return $chamadas->map(function (EditalChamada $chamada) use ($chamadas, $prefixo): string {
            $inicio = $chamada->{"{$prefixo}_inicio"};
            $fim = $chamada->{"{$prefixo}_fim"};
            $periodo = $inicio !== null && $fim !== null
                ? $inicio->format('d/m/Y').' a '.$fim->format('d/m/Y')
                : 'Não cadastrado';

            return $chamadas->count() > 1 ? "{$periodo} ({$chamada->nome})" : $periodo;
        })->values()->all();
    }
}
