<?php

namespace App\Support\EditalIa;

use App\Models\ImportacaoQuestionario;
use App\Support\ComandoEmSegundoPlano;

/**
 * Inicia a análise da importação do questionário num processo separado
 * (`php artisan questionarios:analisar {id} {execucao}`), sem fila, como LancadorDeAnalise. Resolvido pelo container,
 * para os testes o trocarem por um que roda o comando na hora.
 */
class LancadorDeAnaliseDoQuestionario
{
    public function lancar(ImportacaoQuestionario $importacao): void
    {
        ComandoEmSegundoPlano::lancar('questionarios:analisar', [(int) $importacao->getKey(), (string) $importacao->execucao]);
    }
}
