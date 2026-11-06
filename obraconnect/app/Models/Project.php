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
    'code', 'name', 'client_id', 'owner_name', 'type', 'address', 'postal_code', 'city',
    'contract_value_cents', 'starts_on', 'planned_end_on', 'work_calendar_id', 'cost_center_id', 'status', 'description',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Null = calendário por omissão da empresa (App\Calendar\WorkCalendars::forProject). */
    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
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

    public function folders(): HasMany
    {
        return $this->hasMany(DocumentFolder::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ProjectLocation::class);
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ConstructionElement::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
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
