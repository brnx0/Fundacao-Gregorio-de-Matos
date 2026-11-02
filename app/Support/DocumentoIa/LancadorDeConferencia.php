<?php

namespace App\Support\DocumentoIa;

use App\Models\InscricaoDocumento;
use App\Support\ComandoEmSegundoPlano;

/**
 * Inicia a conferência do documento da inscrição num processo separado (`php artisan inscricoes:conferir {id}
 * {execucao}`), sem fila, com ComandoEmSegundoPlano: a requisição volta na hora, um processo por documento.
 * Resolvido pelo container, para os testes o trocarem por um que roda o comando na hora ou que só registra.
 */
class LancadorDeConferencia
{
    public function lancar(InscricaoDocumento $documento): void
    {
        ComandoEmSegundoPlano::lancar('inscricoes:conferir', [(int) $documento->getKey(), (string) $documento->execucao]);
    }
}
