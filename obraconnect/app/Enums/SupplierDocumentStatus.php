<?php

namespace App\Enums;

/** A ler (DocIntel) → por rever → lançado; ou rejeitado (duplicado, não é da empresa...). */
enum SupplierDocumentStatus: string
{
    case Reading = 'reading';
    case ToReview = 'to_review';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'A ler',
            self::ToReview => 'Por rever',
            self::Posted => 'Lançado',
            self::Rejected => 'Rejeitado',
        };
    }
}
