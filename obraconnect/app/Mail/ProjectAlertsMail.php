<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Alertas automáticos novos das obras (uma mensagem por pessoa, com todos os seus). */
class ProjectAlertsMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{level: string, title: string, detail: string, project: string|null, url: string}>  $alerts
     */
    public function __construct(
        public readonly string $companyName,
        public readonly array $alerts,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->alerts);
        $first = $this->alerts[0];

        return new Envelope(subject: ($count === 1
            ? 'Alerta: '.$first['title'].($first['project'] ? ' — '.strtok($first['project'], ' ') : '')
            : "{$count} alertas das obras")." — {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.project-alerts');
    }
}
