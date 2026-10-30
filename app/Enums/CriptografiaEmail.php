<?php

namespace App\Enums;

/** Criptografia da conexão com o servidor SMTP; scheme() é o valor que o mailer do Laravel espera. */
enum CriptografiaEmail: string
{
    case Tls = 'tls';
    case Ssl = 'ssl';

    public function rotulo(): string
    {
        return match ($this) {
            self::Tls => 'TLS (porta 587)',
            self::Ssl => 'SSL (porta 465)',
        };
    }

    public function scheme(): string
    {
        return match ($this) {
            // STARTTLS: começa sem criptografia e passa para TLS logo depois de conectar.
            self::Tls => 'smtp',
            self::Ssl => 'smtps',
        };
    }
}
