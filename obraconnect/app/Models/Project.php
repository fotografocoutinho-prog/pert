<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Obra. Quem a vê e o que pode fazer nela é resolvido em App\Access\AccessResolver
 * (perfil na empresa com "acesso a todas as obras" ou perfil na equipa da obra).
 */
#[Fillable([
    'code', 'name', 'owner_name', 'type', 'address', 'postal_code', 'city',
    'contract_value_cents', 'starts_on', 'planned_end_on', 'status', 'description',
])]
class Project extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'planning',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'type' => ProjectType::class,
            'contract_value_cents' => 'integer',
            'starts_on' => 'date',
            'planned_end_on' => 'date',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function diaryEntries(): HasMany
    {
        return $this->hasMany(DiaryEntry::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }

    public function scopeCountingAsActive(Builder $query): void
    {
        $query->whereIn('status', ProjectStatus::activeValues());
    }
}
