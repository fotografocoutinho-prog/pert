<?php

namespace App\Enums;

/** Natureza de um custo (mapa de custos da obra). */
enum CostCategory: string
{
    case Labor = 'labor';
    case Material = 'material';
    case Equipment = 'equipment';
    case Subcontract = 'subcontract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Mão de obra',
            self::Material => 'Materiais',
            self::Equipment => 'Equipamentos',
            self::Subcontract => 'Subempreitadas',
            self::Other => 'Outros',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
