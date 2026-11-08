<?php

namespace App\Enums;

/** Entidade externa da empresa. */
enum PartnerKind: string
{
    case Supplier = 'supplier';
    case Subcontractor = 'subcontractor';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Fornecedor',
            self::Subcontractor => 'Subempreiteiro',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
