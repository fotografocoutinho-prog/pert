<?php

namespace App\Enums;

enum IssueSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Menor',
            self::Major => 'Maior',
            self::Critical => 'Crítica',
        };
    }
}
