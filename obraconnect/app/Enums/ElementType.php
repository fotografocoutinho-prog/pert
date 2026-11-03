<?php

namespace App\Enums;

enum ElementType: string
{
    case Footing = 'footing';
    case Column = 'column';
    case Beam = 'beam';
    case Slab = 'slab';
    case Wall = 'wall';
    case Stair = 'stair';
    case RetainingWall = 'retaining_wall';
    case Roof = 'roof';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Footing => 'Sapata',
            self::Column => 'Pilar',
            self::Beam => 'Viga',
            self::Slab => 'Laje',
            self::Wall => 'Parede',
            self::Stair => 'Escada',
            self::RetainingWall => 'Muro de suporte',
            self::Roof => 'Cobertura',
            self::Other => 'Outro',
        };
    }

    /** Prefixo do código automático (P1, V12, L3...), como nas peças desenhadas. */
    public function prefix(): string
    {
        return match ($this) {
            self::Footing => 'S',
            self::Column => 'P',
            self::Beam => 'V',
            self::Slab => 'L',
            self::Wall => 'PA',
            self::Stair => 'E',
            self::RetainingWall => 'M',
            self::Roof => 'C',
            self::Other => 'X',
        };
    }
}
