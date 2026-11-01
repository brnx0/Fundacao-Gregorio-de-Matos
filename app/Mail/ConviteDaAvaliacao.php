<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Convite para avaliar inscrições: leva o link pessoal do avaliador e os editais em que ele foi escolhido. */
class ConviteDaAvaliacao extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{titulo: string, periodo: string}>  $editais
     */
    public function __construct(
        public string $nome,
        public string $link,
        public array $editais,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Convite para avaliar inscrições · Portal da FGM');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.convite-avaliacao');
    }
}
