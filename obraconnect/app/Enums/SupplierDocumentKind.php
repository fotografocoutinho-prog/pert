<?php

namespace App\Enums;

/** Tipo de documento de fornecedor e o que acontece quando é lançado. */
enum SupplierDocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case DeliveryNote = 'delivery_note';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Fatura',
            self::CreditNote => 'Nota de crédito',
            self::Receipt => 'Talão / fatura simplificada',
            self::DeliveryNote => 'Guia de remessa / transporte',
            self::Other => 'Outro documento',
        };
    }

    /** Conta como custo (realizado) quando é lançado. */
    public function isCost(): bool
    {
        return in_array($this, [self::Invoice, self::CreditNote, self::Receipt], true);
    }

    /**
     * Tipo do DocIntel (app/core/enums.py) → tipo daqui. Um recibo é a prova de pagamento de uma
     * fatura: não é custo outra vez, por isso fica como "outro".
     */
    public static function fromReader(?string $type): self
    {
        return match ($type) {
            'invoice', 'simplified_invoice', 'invoice_receipt', 'debit_note' => self::Invoice,
            'thermal_receipt' => self::Receipt,
            'credit_note' => self::CreditNote,
            'delivery_note', 'transport_document' => self::DeliveryNote,
            default => self::Other,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
