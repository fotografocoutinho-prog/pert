<?php

namespace App\Enums;

enum MediaStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'A processar',
            self::Ready => 'Pronta',
            self::Failed => 'Sem pré-visualização',
        };
    }
}
