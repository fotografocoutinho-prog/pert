<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Ocorrências e não conformidades com o prazo ultrapassado (uma mensagem por pessoa). */
class IssuesOverdueMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{reference: string, title: string, project: string, due_on: string, assignee: string|null, mine: bool, url: string}>  $issues
     */
    public function __construct(
        public readonly string $companyName,
        public readonly array $issues,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->issues);

        return new Envelope(subject: ($count === 1 ? "Prazo ultrapassado: {$this->issues[0]['reference']}" : "{$count} ocorrências com o prazo ultrapassado")." — {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.issues-overdue');
    }
}
