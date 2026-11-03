<?php

namespace App\Enums;

enum LocationKind: string
{
    case Building = 'building';
    case Floor = 'floor';
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'Edifício',
            self::Floor => 'Piso',
            self::Zone => 'Zona',
        };
    }

    /**
     * Onde pode ficar: edifício na raiz; piso dentro de edifício; zona dentro de piso
     * ou diretamente no edifício (obras de um só nível, arranjos exteriores).
     *
     * @return list<self|null> null = raiz da obra
     */
    public function allowedParents(): array
    {
        return match ($this) {
            self::Building => [null],
            self::Floor => [self::Building],
            self::Zone => [self::Building, self::Floor],
        };
    }
}
