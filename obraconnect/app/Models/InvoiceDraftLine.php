<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'description', 'unit', 'quantity', 'unit_price_cents', 'discount_pct', 'vat_rate', 'sort_order'])]
class InvoiceDraftLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function invoiceDraft(): BelongsTo
    {
        return $this->belongsTo(InvoiceDraft::class);
    }

    /** @return array{0: int, 1: int} [valor sem IVA, IVA] em cêntimos */
    public function amounts(): array
    {
        $net = (int) round((float) $this->quantity * $this->unit_price_cents * (1 - (float) $this->discount_pct / 100));

        return [$net, (int) round($net * (float) $this->vat_rate / 100)];
    }
}
