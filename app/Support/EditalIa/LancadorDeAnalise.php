<?php

namespace App\Support\EditalIa;

use App\Models\ImportacaoEdital;
use App\Support\ComandoEmSegundoPlano;

/**
 * Inicia a análise da importação num processo separado (`php artisan editais:analisar {id} {execucao}`), sem fila,
 * com ComandoEmSegundoPlano: a requisição volta na hora. Resolvido pelo container, para os testes o trocarem por um
 * que roda o comando na hora.
 */
class LancadorDeAnalise
{
    public function lancar(ImportacaoEdital $importacao): void
    {
        ComandoEmSegundoPlano::lancar('editais:analisar', [(int) $importacao->getKey(), (string) $importacao->execucao]);
    }
}
