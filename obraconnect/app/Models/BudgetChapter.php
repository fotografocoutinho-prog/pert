<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Capítulo (ou subcapítulo) de um orçamento.
 */
#[Fillable(['parent_id', 'code', 'name', 'sort_order'])]
class BudgetChapter extends Model
{
    use BelongsToTenant;

    protected $table = 'budget_chapters';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BudgetChapter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BudgetChapter::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'chapter_id')->orderBy('sort_order')->orderBy('id');
    }
}
