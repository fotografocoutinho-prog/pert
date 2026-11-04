<?php

namespace App\Models;

use App\Enums\InspectionStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inspeção de uma obra. Em rascunho prepara-se (pontos, local, data pretendida); depois só
 * muda por App\Inspections\InspectionWorkflow e cada passagem fica no histórico encadeado.
 */
#[Fillable(['title', 'description', 'location_id', 'element_id', 'specialty_id', 'scheduled_for', 'remote'])]
class Inspection extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
        'remote' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'number' => 'integer',
            'template_version' => 'integer',
            'remote' => 'boolean',
            'scheduled_for' => 'datetime',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'evidence_score' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function reinspectionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reinspection_of_id');
    }

    public function reinspections(): HasMany
    {
        return $this->hasMany(self::class, 'reinspection_of_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InspectionEvent::class)->orderBy('sequence');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'location_id');
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(ConstructionElement::class, 'element_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === InspectionStatus::Draft;
    }
}
