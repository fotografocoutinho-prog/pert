<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Convite para o portal de subempreiteiros. */
class PortalInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $partnerName,
        public readonly string $inviterName,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->companyName}: acesso ao portal de subempreiteiros");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal-invitation', with: [
            'url' => url('/portal/convite/'.$this->token),
        ]);
    }
}
