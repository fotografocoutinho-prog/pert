<?php

namespace App\Billing;

use App\Models\PlatformSetting;
use App\Models\SubscriptionOrder;
use App\Models\User;

/**
 * Dados para as empresas pagarem a subscrição por transferência: titular, IBAN, banco, BIC, email
 * para os comprovativos e o texto das indicações. Editam-se em Plataforma → Dados de pagamento;
 * sem nada guardado não há IBAN e o texto é BILLING_PAYMENT_INSTRUCTIONS.
 */
final class PaymentDetails
{
    public const KEY = 'billing.payment';

    public const FIELDS = ['account_holder', 'iban', 'bank', 'bic', 'proof_email', 'instructions'];

    public const LABELS = [
        'account_holder' => 'titular', 'iban' => 'IBAN', 'bank' => 'banco', 'bic' => 'BIC/SWIFT',
        'proof_email' => 'email para comprovativos', 'instructions' => 'indicações de pagamento',
    ];

    public static function defaultInstructions(): string
    {
        return (string) config('obraconnect.billing.payment_instructions');
    }

    /** @return array{account_holder: ?string, iban: ?string, bank: ?string, bic: ?string, proof_email: ?string, instructions: string} */
    public static function current(): array
    {
        $saved = PlatformSetting::for(self::KEY)?->value ?? [];
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $saved[$field] ?? null;
            $out[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $out['instructions'] ??= self::defaultInstructions();

        return $out;
    }

    /** O que as empresas veem na página Subscrição. */
    public static function forCompanies(): array
    {
        $d = self::current();

        return [
            'transfer' => $d['iban'] !== null,
            'account_holder' => $d['account_holder'],
            'iban' => self::format($d['iban']),
            'bank' => $d['bank'],
            'bic' => $d['bic'],
            'proof_email' => $d['proof_email'],
            'instructions' => $d['instructions'],
        ];
    }

    /** O que se escreve no descritivo da transferência, para se saber a que pedido corresponde. */
    public static function reference(SubscriptionOrder $order): string
    {
        return 'ObraConnect '.$order->id;
    }

    /**
     * Linhas para o email de quem fez o pedido.
     *
     * @return list<string>
     */
    public static function lines(SubscriptionOrder $order): array
    {
        $d = self::current();
        $lines = [];
        if ($d['iban'] !== null) {
            $who = array_values(array_filter([$d['account_holder'] ? 'titular '.$d['account_holder'] : null, $d['bank'], $d['bic'] ? 'BIC/SWIFT '.$d['bic'] : null]));
            $lines[] = 'Pagamento por transferência bancária para o IBAN '.self::format($d['iban']).($who ? ' ('.implode(', ', $who).')' : '').'.';
            $lines[] = 'No descritivo da transferência indique: '.self::reference($order).'.';
            if ($d['proof_email'] !== null) {
                $lines[] = "Envie o comprovativo para {$d['proof_email']}.";
            }
        }
        $lines[] = $d['instructions'];

        return $lines;
    }

    /** Tira espaços, pontos e traços; um NIB português (21 algarismos) passa a IBAN (PT50 + NIB). */
    public static function normalizeIban(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $iban = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $value));
        if ($iban === '') {
            return null;
        }

        return preg_match('/^\d{21}$/', $iban) ? 'PT50'.$iban : $iban;
    }

    /** Formato e algarismos de controlo (ISO 13616, mod 97). Em Portugal o IBAN tem 25 caracteres. */
    public static function validIban(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) || (str_starts_with($iban, 'PT') && strlen($iban) !== 25)) {
            return false;
        }
        $digits = (string) preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), substr($iban, 4).substr($iban, 0, 4));
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest === 1;
    }

    public static function format(?string $iban): ?string
    {
        return $iban === null ? null : trim(chunk_split($iban, 4, ' '));
    }

    /** Para avisos e auditoria: só o país e os últimos 4 algarismos. */
    public static function mask(?string $iban): string
    {
        return $iban === null ? 'sem IBAN' : substr($iban, 0, 4).' … '.substr($iban, -4);
    }

    /**
     * Guarda e devolve os campos que mudaram.
     *
     * @param  array<string, ?string>  $data
     * @return array{0: array<string, ?string>, 1: array<string, ?string>, 2: list<string>} antes, depois, campos alterados
     */
    public static function save(array $data, User $by): array
    {
        $before = self::current();
        $value = [];
        foreach (self::FIELDS as $field) {
            $value[$field] = isset($data[$field]) && trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;
        }
        if ($value['instructions'] === self::defaultInstructions()) {
            $value['instructions'] = null; // continua a acompanhar o texto por omissão
        }
        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by_user_id' => $by->id]);
        $after = self::current();

        return [$before, $after, array_values(array_filter(self::FIELDS, fn (string $f) => $before[$f] !== $after[$f]))];
    }
}
