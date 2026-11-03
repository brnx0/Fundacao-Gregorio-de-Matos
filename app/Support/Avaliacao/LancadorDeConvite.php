<?php

namespace App\Support\Avaliacao;

use App\Models\Avaliador;
use App\Support\ComandoEmSegundoPlano;

/**
 * Inicia o envio do convite num processo separado (`php artisan avaliacao:enviar-convite {avaliador} {execucao}`), sem
 * fila, com ComandoEmSegundoPlano: a requisição volta na hora. Resolvido pelo container, para os testes o trocarem por
 * um que roda o comando na hora.
 */
class LancadorDeConvite
{
    public function lancar(Avaliador $avaliador, string $execucao): void
    {
        ComandoEmSegundoPlano::lancar('avaliacao:enviar-convite', [(int) $avaliador->getKey(), $execucao]);
    }
}
