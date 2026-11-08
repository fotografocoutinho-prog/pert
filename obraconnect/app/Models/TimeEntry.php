<?php

namespace App\Models;

use App\Enums\TimeEntryStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas de um trabalhador numa obra num dia (com o custo/hora desse momento).
 */
#[Fillable(['worker_id', 'activity_id', 'work_date', 'hours', 'overtime_hours', 'description'])]
class TimeEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'time_entries';

    protected $attributes = [
        'status' => 'pending',
        'overtime_hours' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => TimeEntryStatus::class,
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'hourly_cost_cents' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Horas extra pagas a 150 %. */
    public const OVERTIME_FACTOR = 1.5;

    public function costCents(): int
    {
        return (int) round(((float) $this->hours + (float) $this->overtime_hours * self::OVERTIME_FACTOR) * $this->hourly_cost_cents);
    }
}
