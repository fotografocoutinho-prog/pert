<?php

namespace App\Enums;

/** Estado de uma compra. Aprovada conta como custo comprometido; faturada, como realizado. */
enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Received = 'received';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Approved => 'Aprovada',
            self::Received => 'Recebida',
            self::Invoiced => 'Faturada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
