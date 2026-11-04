<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Centro de custo, em árvore. Uma obra pode ter um. Com filhos ou obras não se apaga: arquiva-se
 * (os custos da fase 4 vão ser imputados aqui).
 */
#[Fillable(['parent_id', 'code', 'name'])]
class CostCenter extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
