<?php

namespace App\Enums;

/** Situação de um equipamento. */
enum EquipmentStatus: string
{
    case Available = 'available';
    case InUse = 'in_use';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponível',
            self::InUse => 'Em obra',
            self::Maintenance => 'Em manutenção',
            self::Retired => 'Abatido',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
