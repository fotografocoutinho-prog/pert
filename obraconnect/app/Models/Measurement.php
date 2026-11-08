<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medição do executado num artigo do orçamento. Entra num auto quando este é aprovado.
 */
#[Fillable(['budget_item_id', 'measured_on', 'description', 'count', 'length', 'width', 'height', 'quantity', 'location_id', 'element_id'])]
class Measurement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'count' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'quantity' => 'decimal:3',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
