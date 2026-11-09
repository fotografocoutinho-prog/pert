<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso à equipa da plataforma: verificações do sistema que falharam ou voltaram ao normal. */
class SystemAlertMail extends Mailable
{
    /**
     * @param  list<array{label: string, detail: string}>  $failing  passaram a falhar
     * @param  list<array{label: string, detail: string}>  $still  continuam a falhar (lembrete diário)
     * @param  list<array{label: string, detail: string}>  $recovered  voltaram ao normal
     */
    public function __construct(
        public readonly array $failing,
        public readonly array $still,
        public readonly array $recovered,
    ) {}

    public function envelope(): Envelope
    {
        $problems = count($this->failing) + count($this->still);
        $subject = $problems > 0
            ? ($problems === 1 ? 'Problema no ObraConnect: '.($this->failing[0]['label'] ?? $this->still[0]['label']) : "{$problems} problemas no ObraConnect")
            : 'ObraConnect: tudo normal outra vez';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.system-alert');
    }
}
