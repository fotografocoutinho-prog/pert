<?php

namespace App\Enums;

/** Tipo de recurso da biblioteca de preços. */
enum ResourceKind: string
{
    case Material = 'material';
    case Labor = 'labor';
    case Equipment = 'equipment';
    case Subcontract = 'subcontract';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Labor => 'Mão de obra',
            self::Equipment => 'Equipamento',
            self::Subcontract => 'Subempreitada',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
