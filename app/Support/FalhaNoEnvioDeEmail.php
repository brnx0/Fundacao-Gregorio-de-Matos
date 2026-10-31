<?php

namespace App\Support;

use Throwable;

/**
 * Explica, em português, por que um envio de e-mail falhou. Nunca repete o texto do servidor: ele pode trazer o
 * usuário e detalhes da conexão que não devem aparecer na tela.
 */
final class FalhaNoEnvioDeEmail
{
    public static function mensagem(Throwable $falha, string $host, int $porta): string
    {
        $texto = $falha->getMessage();

        if ($falha->getCode() === 535 || str_contains($texto, '"535"') || str_contains($texto, 'Failed to authenticate')) {
            return 'O servidor recusou o usuário ou a senha. No Gmail, use uma senha de app, não a senha da conta.';
        }

        if (str_contains($texto, 'Connection could not be established') || str_contains($texto, 'Connection to')) {
            return "Não foi possível conectar a {$host} na porta {$porta}. Confira o servidor, a porta e a criptografia.";
        }

        return 'O servidor de e-mail recusou o envio. Confira os dados e tente de novo.';
    }
}
