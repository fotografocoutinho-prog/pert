<?php

namespace App\Enums;

enum DiaryEntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Submetido',
        };
    }
}
