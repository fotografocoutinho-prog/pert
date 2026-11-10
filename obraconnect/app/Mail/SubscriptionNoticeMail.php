<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avisos sobre a subscrição (pedidos, mudanças de plano), à empresa ou à equipa de faturação. */
class SubscriptionNoticeMail extends Mailable
{
    use Queueable;

    /** @param  list<string>  $lines  texto simples (é escapado no modelo) */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $lines,
        public readonly string $url,
        public readonly string $button,
        public readonly string $footer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.subscription-notice');
    }
}
