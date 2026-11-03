<?php

namespace App\Enums;

enum ClientType: string
{
    case Private = 'private';
    case Company = 'company';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Particular',
            self::Company => 'Empresa',
            self::Public => 'Entidade pública',
        };
    }
}
