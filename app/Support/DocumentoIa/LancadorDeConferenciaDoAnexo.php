<?php

namespace App\Support\DocumentoIa;

use App\Models\PessoaFisicaAnexo;
use App\Support\ComandoEmSegundoPlano;

/**
 * Inicia a conferência do anexo do cadastro num processo separado (`php artisan cadastros:conferir {id} {execucao}`),
 * sem fila, com ComandoEmSegundoPlano: a requisição volta na hora, um processo por arquivo. Resolvido pelo container,
 * para os testes o trocarem por um que roda o comando na hora ou que só registra.
 */
class LancadorDeConferenciaDoAnexo
{
    public function lancar(PessoaFisicaAnexo $anexo): void
    {
        ComandoEmSegundoPlano::lancar('cadastros:conferir', [(int) $anexo->getKey(), (string) $anexo->execucao]);
    }
}
