<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\PurchaseStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compra a um fornecedor. Aprovada conta como custo comprometido; faturada, como realizado.
 */
#[Fillable(['partner_id', 'category', 'cost_center_id', 'ordered_on', 'expected_on', 'notes'])]
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_orders';

    protected $attributes = [
        'status' => 'draft',
        'category' => 'material',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'category' => CostCategory::class,
            'number' => 'integer',
            'ordered_on' => 'date',
            'expected_on' => 'date',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'invoiced_on' => 'date',
            'invoiced_cents' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function totalCents(): int
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return (int) round($lines->sum(fn (PurchaseOrderLine $l) => (float) $l->quantity * $l->unit_price_cents));
    }
}
