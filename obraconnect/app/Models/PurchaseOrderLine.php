<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de uma compra.
 */
#[Fillable(['resource_id', 'budget_item_id', 'description', 'unit', 'quantity', 'unit_price_cents', 'received_quantity', 'sort_order'])]
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_cents' => 'integer',
            'received_quantity' => 'decimal:3',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
