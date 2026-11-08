<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato de subempreitada de uma obra.
 */
#[Fillable(['partner_id', 'code', 'title', 'retention_pct', 'starts_on', 'ends_on', 'notes'])]
class Contract extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
        'retention_pct' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'retention_pct' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
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

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function valueCents(): int
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return (int) round($items->sum(fn (ContractItem $i) => (float) $i->quantity * $i->unit_price_cents));
    }
}
