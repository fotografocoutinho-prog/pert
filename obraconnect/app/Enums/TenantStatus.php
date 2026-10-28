<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::ReadOnly => 'Só leitura',
            self::Suspended => 'Suspensa',
        };
    }
}
