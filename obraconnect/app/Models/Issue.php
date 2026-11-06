<?php

namespace App\Models;

use App\Enums\IssueKind;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ocorrência ou não conformidade de uma obra: alguém a resolve até um prazo e outra pessoa
 * valida. Com o prazo ultrapassado é escalonada (App\Console\Commands\EscalateOverdueIssues).
 */
#[Fillable(['kind', 'severity', 'title', 'description', 'location_id', 'element_id', 'specialty_id', 'assignee_user_id', 'due_on'])]
class Issue extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'kind' => IssueKind::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'number' => 'integer',
            'due_on' => 'date',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function inspectionItem(): BelongsTo
    {
        return $this->belongsTo(InspectionItem::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IssueEvent::class)->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Por resolver e com o prazo já passado (whereDate: no SQLite a data fica guardada com hora). */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereIn('status', [IssueStatus::Open->value, IssueStatus::InProgress->value])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now('Europe/Lisbon')->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status->isPending() && $this->due_on !== null && $this->due_on->toDateString() < now('Europe/Lisbon')->toDateString();
    }

    /** "NC 12" / "OC 3". */
    public function reference(): string
    {
        return "{$this->kind->short()} {$this->number}";
    }
}
