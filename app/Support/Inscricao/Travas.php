<?php

namespace App\Support\Inscricao;

use App\Models\Edital;
use App\Models\Inscricao;

/**
 * Travas de linha dentro da transação, sempre o edital e depois a inscrição (a mesma ordem de InscricaoController),
 * para quem decide, marca ou recorre ao mesmo tempo esperar um pelo outro.
 */
class Travas
{
    public static function edital(Edital $edital): void
    {
        Edital::query()->whereKey($edital->id)->lockForUpdate()->value('id');
    }

    /**
     * Relê a inscrição travando a linha: a situação que vale é a da linha travada, não a da cópia lida antes da
     * transação. A relação `edital` da cópia é repassada.
     */
    public static function inscricao(Inscricao $inscricao): Inscricao
    {
        $travada = Inscricao::query()->whereKey($inscricao->id)->lockForUpdate()->firstOrFail();
        $travada->setRelation('edital', $inscricao->edital);

        return $travada;
    }
}
