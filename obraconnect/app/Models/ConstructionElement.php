<?php

namespace App\Models;

use App\Enums\ElementType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Elemento construtivo (pilar, viga, laje...) numa localização da obra. Código único na obra. */
#[Fillable(['location_id', 'element_type', 'code', 'name', 'notes'])]
class ConstructionElement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['element_type' => ElementType::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'location_id');
    }
}
