<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso a uma pessoa de um subempreiteiro com acesso ao portal. */
class PortalNoticeMail extends Mailable
{
    use Queueable;

    /** @param  list<string>  $lines  texto simples (é escapado no modelo) */
    public function __construct(
        public readonly string $companyName,
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $lines,
        public readonly string $url,
        public readonly string $button,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal-notice');
    }
}
