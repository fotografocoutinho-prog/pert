<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atividade do planeamento. As datas saem do CPM (App\Planning\Schedule), não se guardam.
 */
#[Fillable(['parent_id', 'code', 'name', 'duration_days', 'optimistic_days', 'likely_days', 'pessimistic_days', 'start_no_earlier_than', 'progress_pct', 'location_id', 'element_id', 'sort_order'])]
class Activity extends Model
{
    use BelongsToTenant;

    protected $table = 'activities';

    protected $attributes = [
        'duration_days' => 1,
        'progress_pct' => 0,
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'optimistic_days' => 'decimal:2',
            'likely_days' => 'decimal:2',
            'pessimistic_days' => 'decimal:2',
            'start_no_earlier_than' => 'date',
            'progress_pct' => 'integer',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Activity::class, 'parent_id');
    }

    public function predecessors(): HasMany
    {
        return $this->hasMany(ActivityDependency::class, 'successor_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(ActivityDependency::class, 'predecessor_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }
}
