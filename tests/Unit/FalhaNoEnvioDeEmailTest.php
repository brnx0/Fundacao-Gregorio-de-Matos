<?php

namespace Tests\Unit;

use App\Support\FalhaNoEnvioDeEmail;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;

class FalhaNoEnvioDeEmailTest extends TestCase
{
    public function test_usuario_ou_senha_recusados(): void
    {
        $falha = new TransportException('Failed to authenticate on SMTP server with username "fgm@gmail.com" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted.".', 535);

        $this->assertSame(
            'O servidor recusou o usuário ou a senha. No Gmail, use uma senha de app, não a senha da conta.',
            FalhaNoEnvioDeEmail::mensagem($falha, 'smtp.gmail.com', 587),
        );
    }

    public function test_sem_conexao(): void
    {
        $falha = new TransportException('Connection could not be established with host "smtp.gmail.com:25": stream_socket_client(): Unable to connect');

        $this->assertSame(
            'Não foi possível conectar a smtp.gmail.com na porta 25. Confira o servidor, a porta e a criptografia.',
            FalhaNoEnvioDeEmail::mensagem($falha, 'smtp.gmail.com', 25),
        );
    }

    public function test_outra_falha_nao_repete_o_texto_do_servidor(): void
    {
        $falha = new RuntimeException('segredo-no-texto');

        $mensagem = FalhaNoEnvioDeEmail::mensagem($falha, 'smtp.gmail.com', 587);

        $this->assertSame('O servidor de e-mail recusou o envio. Confira os dados e tente de novo.', $mensagem);
        $this->assertStringNotContainsString('segredo', $mensagem);
    }
}
