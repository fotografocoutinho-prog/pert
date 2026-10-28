<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Suspended => 'Suspenso',
        };
    }
}
