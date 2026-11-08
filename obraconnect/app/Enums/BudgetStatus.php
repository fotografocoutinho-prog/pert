<?php

namespace App\Enums;

/** Estado de um orçamento. Aprovado = base do contrato e do custo previsto; não se altera. */
enum BudgetStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Approved => 'Aprovado',
            self::Superseded => 'Substituído por revisão',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
