<?php

namespace App\Support\Avaliacao;

use App\Models\Avaliador;
use Illuminate\Support\Facades\Process;

use function Illuminate\Support\php_binary;

/**
 * Inicia o envio do convite num processo separado (`php artisan avaliacao:enviar-convite {avaliador} {execucao}`), sem
 * fila: o comando roda solto em segundo plano e a requisição volta na hora. Usa o PHP de linha de comando (no PHP-FPM,
 * PHP_BINARY é o php-fpm). Resolvido pelo container, para os testes o trocarem por um que roda o comando na hora.
 */
class LancadorDeConvite
{
    public function lancar(Avaliador $avaliador, string $execucao): void
    {
        Process::path(base_path())->run(sprintf(
            'nohup %s %s avaliacao:enviar-convite %d %s > /dev/null 2>&1 &',
            escapeshellarg(php_binary()),
            escapeshellarg(base_path('artisan')),
            (int) $avaliador->getKey(),
            escapeshellarg($execucao),
        ))->throw();
    }
}
