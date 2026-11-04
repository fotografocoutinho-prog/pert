<?php

namespace App\Models;

use App\Enums\LocationKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Edifício, piso ou zona de uma obra (ver LocationKind para o encadeamento). */
#[Fillable(['kind', 'name', 'parent_id', 'sort_order'])]
class ProjectLocation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => LocationKind::class,
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ConstructionElement::class, 'location_id');
    }

    public function diaryItems(): HasMany
    {
        return $this->hasMany(DiaryItem::class, 'location_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'location_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'location_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'location_id');
    }

    /** Tem linhas do diário, fotografias ou documentos ligados (não se pode apagar). */
    public function isReferenced(): bool
    {
        return $this->diaryItems()->exists() || $this->media()->exists() || $this->documents()->exists() || $this->inspections()->exists();
    }
}
