<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

use function Illuminate\Support\php_binary;

/**
 * Roda um comando do artisan solto em segundo plano, sem fila: a requisição volta na hora. Usa o PHP de linha de
 * comando (no PHP-FPM, PHP_BINARY é o php-fpm). No Linux, com `nohup … &`; no Windows (ambiente local), onde o
 * Process passa pelo cmd.exe, com `start /B`.
 */
class ComandoEmSegundoPlano
{
    /**
     * Variáveis do sistema que o processo precisa no Windows. Fora da linha de comando (no `artisan serve`), o Process
     * só repassa as que também estão em $_SERVER e o filho nasce sem elas: sem SYSTEMROOT, não resolve nenhum
     * endereço (cURL error 6); sem TEMP, os temporários vão para C:\Windows.
     */
    private const array DO_WINDOWS = ['SYSTEMROOT', 'PATH', 'TEMP', 'TMP'];

    /**
     * @param  list<int|string>  $argumentos  Inteiros vão como estão; textos, escapados para o shell.
     */
    public static function lancar(string $comando, array $argumentos, bool $noWindows = PHP_OS_FAMILY === 'Windows'): void
    {
        $linha = implode(' ', [
            escapeshellarg(php_binary()),
            escapeshellarg(base_path('artisan')),
            $comando,
            ...array_map(fn (int|string $argumento): string => is_int($argumento) ? (string) $argumento : escapeshellarg($argumento), $argumentos),
        ]);

        if (! $noWindows) {
            Process::path(base_path())->run("nohup {$linha} > /dev/null 2>&1 &")->throw();

            return;
        }

        $ambiente = array_filter(
            array_combine(self::DO_WINDOWS, array_map(getenv(...), self::DO_WINDOWS)),
            fn (string|false $valor): bool => $valor !== false,
        );

        Process::path(base_path())->env($ambiente)->run("start \"\" /B {$linha} > NUL 2>&1")->throw();
    }
}
