<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Recebe apenas valores simples: o token em claro nunca é guardado em base de dados.
 */
class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $roleName,
        public readonly string $inviterName,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Convite para {$this->companyName} no ObraConnect");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'url' => url('/convite/'.$this->token),
        ]);
    }
}
