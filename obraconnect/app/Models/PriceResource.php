<?php

namespace App\Models;

use App\Enums\ResourceKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recurso da biblioteca de preços (material, mão de obra, equipamento, subempreitada).
 */
#[Fillable(['kind', 'code', 'name', 'unit', 'unit_cost_cents'])]
class PriceResource extends Model
{
    use BelongsToTenant;

    protected $table = 'resources';

    protected function casts(): array
    {
        return [
            'kind' => ResourceKind::class,
            'unit_cost_cents' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(ArticleComponent::class, 'resource_id');
    }
}
