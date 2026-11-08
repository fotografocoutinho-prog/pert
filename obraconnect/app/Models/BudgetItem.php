<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Artigo de um orçamento: quantidade, custo unitário (com a sua composição) e preço de venda.
 */
#[Fillable(['chapter_id', 'article_id', 'specialty_id', 'code', 'description', 'unit', 'quantity', 'unit_cost_cents', 'unit_price_cents', 'sort_order'])]
class BudgetItem extends Model
{
    use BelongsToTenant;

    protected $table = 'budget_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost_cents' => 'integer',
            'unit_price_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(BudgetChapter::class, 'chapter_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(BudgetItemComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    /** Preço de venda unitário: o escrito, ou custo × encargos e margem do orçamento. */
    public function salePriceCents(Budget $budget): int
    {
        return $this->unit_price_cents ?? (int) round($this->unit_cost_cents * $budget->markup());
    }
}
