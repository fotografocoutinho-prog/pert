<?php

namespace App\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';
    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrador',
            self::Support => 'Suporte técnico',
            self::Billing => 'Faturação',
        };
    }
}
