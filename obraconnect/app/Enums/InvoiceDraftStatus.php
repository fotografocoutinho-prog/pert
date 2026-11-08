<?php

namespace App\Enums;

enum InvoiceDraftStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Ready => 'Pronta a faturar',
            self::Invoiced => 'Faturada',
            self::Cancelled => 'Anulada',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
