<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Estados em que a empresa mantém acesso completo. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** @return list<string> */
    public static function accessValues(): array
    {
        return [self::Trialing->value, self::Active->value, self::PastDue->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Período experimental',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento em atraso',
            self::Canceled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }
}
