<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de uma obra (inspeção pedida, submetida, decidida, ocorrência atribuída...). O texto vem já
 * montado por App\Notifications\Notifier; o que vem dos utilizadores é escapado para Markdown.
 */
class ProjectNoticeMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lines  em Markdown (já escapado)
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $projectLabel,
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $lines,
        public readonly string $url,
        public readonly string $button,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->subjectLine} — {$this->projectLabel}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.project-notice');
    }

    /** Texto de utilizadores dentro de Markdown: sem ligações, ênfases ou títulos escondidos. */
    public static function escape(?string $text): string
    {
        return addcslashes(str_replace(["\r\n", "\r"], "\n", (string) $text), '\\`*_{}[]()#+-.!|<>~');
    }
}
