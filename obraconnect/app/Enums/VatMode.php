<?php

namespace App\Enums;

/** Regime de IVA do orçamento. */
enum VatMode: string
{
    case Normal = 'normal';
    case ReverseCharge = 'reverse_charge';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'IVA à taxa',
            self::ReverseCharge => 'Autoliquidação',
            self::Exempt => 'Isento',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
