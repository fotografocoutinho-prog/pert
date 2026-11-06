<?php

namespace App\Enums;

enum IssueKind: string
{
    case NonConformity = 'nonconformity';
    case Occurrence = 'occurrence';

    public function label(): string
    {
        return match ($this) {
            self::NonConformity => 'Não conformidade',
            self::Occurrence => 'Ocorrência',
        };
    }

    /** Abreviatura usada antes do número (NC 12, OC 3). */
    public function short(): string
    {
        return match ($this) {
            self::NonConformity => 'NC',
            self::Occurrence => 'OC',
        };
    }
}
