<?php

namespace Tests\Feature;

use App\Support\ComandoEmSegundoPlano;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

use function Illuminate\Support\php_binary;

class ComandoEmSegundoPlanoTest extends TestCase
{
    public function test_no_linux_lanca_com_nohup_e_o_php_de_linha_de_comando(): void
    {
        Process::fake();

        ComandoEmSegundoPlano::lancar('editais:analisar', [7, 'abc-123'], noWindows: false);

        Process::assertRan(fn (PendingProcess $processo): bool => $processo->path === base_path()
            && $processo->command === sprintf(
                'nohup %s %s editais:analisar 7 %s > /dev/null 2>&1 &',
                escapeshellarg(php_binary()),
                escapeshellarg(base_path('artisan')),
                escapeshellarg('abc-123'),
            ));
    }

    public function test_no_windows_lanca_com_start_sem_sintaxe_de_shell_unix(): void
    {
        Process::fake();

        ComandoEmSegundoPlano::lancar('editais:analisar', [7, 'abc-123'], noWindows: true);

        Process::assertRan(fn (PendingProcess $processo): bool => $processo->command === sprintf(
            'start "" /B %s %s editais:analisar 7 %s > NUL 2>&1',
            escapeshellarg(php_binary()),
            escapeshellarg(base_path('artisan')),
            escapeshellarg('abc-123'),
        ));
    }

    public function test_no_windows_repassa_ao_processo_as_variaveis_do_sistema_e_nada_mais(): void
    {
        Process::fake();
        $original = getenv('SYSTEMROOT');
        putenv('SYSTEMROOT=C:\Windows');
        putenv('HTTP_PROXY=http://intruso');

        try {
            ComandoEmSegundoPlano::lancar('editais:analisar', [7, 'abc-123'], noWindows: true);
        } finally {
            putenv($original === false ? 'SYSTEMROOT' : "SYSTEMROOT={$original}");
            putenv('HTTP_PROXY');
        }

        Process::assertRan(fn (PendingProcess $processo): bool => ($processo->environment['SYSTEMROOT'] ?? null) === 'C:\Windows'
            && ! array_key_exists('HTTP_PROXY', $processo->environment)
            && array_diff(array_keys($processo->environment), ['SYSTEMROOT', 'PATH', 'TEMP', 'TMP']) === []);
    }

    public function test_falha_ao_iniciar_o_processo_lanca_excecao(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->expectException(ProcessFailedException::class);

        ComandoEmSegundoPlano::lancar('editais:analisar', [7, 'abc-123']);
    }
}
