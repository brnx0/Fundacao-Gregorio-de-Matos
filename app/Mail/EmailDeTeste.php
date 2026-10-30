<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Confere a configuração de e-mail (Retaguarda > Parametrização > E-mail): vai para quem pediu o teste. */
class EmailDeTeste extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Teste de envio · Portal da FGM');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.teste');
    }
}
