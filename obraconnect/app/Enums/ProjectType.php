<?php

namespace App\Enums;

enum ProjectType: string
{
    case NewBuild = 'new_build';
    case Renovation = 'renovation';
    case Extension = 'extension';
    case Demolition = 'demolition';
    case Infrastructure = 'infrastructure';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NewBuild => 'Construção nova',
            self::Renovation => 'Reabilitação',
            self::Extension => 'Ampliação',
            self::Demolition => 'Demolição',
            self::Infrastructure => 'Infraestruturas',
            self::Maintenance => 'Manutenção',
            self::Other => 'Outra',
        };
    }
}
