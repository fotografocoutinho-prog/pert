<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de lista de verificação da empresa (ex.: "Betonagem de lajes"). Cada inspeção copia os
 * pontos no momento em que é criada; alterar o modelo depois não mexe nas inspeções já feitas.
 */
#[Fillable(['name', 'description', 'specialty_id'])]
class ChecklistTemplate extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
