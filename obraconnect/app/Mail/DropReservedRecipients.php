<?php

namespace App\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Tira dos destinatários os endereços de domínios reservados (RFC 2606/6761: .test, .example,
 * .invalid, .localhost e example.com/.net/.org). São os da empresa de demonstração e dos testes:
 * nunca devem sair para a rede. Se não sobrar ninguém, o envio é cancelado.
 */
class DropReservedRecipients
{
    private const RESERVED_TLDS = ['test', 'example', 'invalid', 'localhost'];

    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    public function handle(MessageSending $event): bool
    {
        $message = $event->message;
        if (! $message instanceof Email) {
            return true;
        }

        $dropped = 0;
        foreach (['To', 'Cc', 'Bcc'] as $field) {
            $all = $message->{'get'.$field}();
            $kept = array_values(array_filter($all, fn (Address $a) => ! self::isReserved($a->getAddress())));
            $dropped += count($all) - count($kept);
            if (count($kept) !== count($all)) {
                $message->getHeaders()->remove($field);
                if ($kept !== []) {
                    $message->{strtolower($field)}(...$kept);
                }
            }
        }

        if ($dropped > 0) {
            Log::info('Email: destinatários de domínios reservados retirados.', ['dropped' => $dropped, 'subject' => $message->getSubject()]);
        }

        return $message->getTo() !== [] || $message->getCc() !== [] || $message->getBcc() !== [];
    }

    public static function isReserved(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return false;
        }
        $tld = substr(strrchr('.'.$domain, '.') ?: '', 1);

        return in_array($tld, self::RESERVED_TLDS, true)
            || in_array($domain, self::RESERVED_DOMAINS, true)
            || collect(self::RESERVED_DOMAINS)->contains(fn (string $d) => str_ends_with($domain, '.'.$d));
    }
}
