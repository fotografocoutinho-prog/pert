<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Artigo da biblioteca de preços: simples (custo próprio) ou composto (soma dos recursos).
 */
#[Fillable(['code', 'description', 'unit', 'unit_cost_cents', 'notes'])]
class Article extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'unit_cost_cents' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(ArticleComponent::class, 'article_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Custo unitário: com composição, a soma de recurso × rendimento; sem composição, o escrito. */
    public function unitCostCents(): int
    {
        $components = $this->relationLoaded('components') ? $this->components : $this->components()->with('resource')->get();
        if ($components->isEmpty()) {
            return (int) $this->unit_cost_cents;
        }

        return (int) round($components->sum(fn (ArticleComponent $c) => (float) $c->quantity * (int) $c->resource->unit_cost_cents));
    }
}
