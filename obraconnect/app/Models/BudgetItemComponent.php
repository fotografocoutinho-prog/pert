<?php

namespace App\Models;

use App\Enums\ResourceKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da composição de um artigo do orçamento (cópia, não muda com a biblioteca).
 */
#[Fillable(['resource_id', 'kind', 'name', 'unit', 'quantity', 'unit_cost_cents', 'sort_order'])]
class BudgetItemComponent extends Model
{
    use BelongsToTenant;

    protected $table = 'budget_item_components';

    protected function casts(): array
    {
        return [
            'kind' => ResourceKind::class,
            'quantity' => 'decimal:4',
            'unit_cost_cents' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
