<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ExportReadyMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly ?CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Exportação de dados pronta — {$this->companyName}");
    }

    public function content(): Content
    {
        // A ligação abre a aplicação; o download exige sessão iniciada e permissão.
        return new Content(markdown: 'mail.export-ready', with: [
            'url' => url('/app/exportacoes'),
        ]);
    }
}
