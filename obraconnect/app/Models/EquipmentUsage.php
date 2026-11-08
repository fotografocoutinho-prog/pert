<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas de utilização de um equipamento numa obra.
 */
#[Fillable(['project_id', 'used_on', 'hours', 'notes'])]
class EquipmentUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'equipment_usages';

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'hours' => 'decimal:2',
            'hourly_cost_cents' => 'integer',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function costCents(): int
    {
        return (int) round((float) $this->hours * $this->hourly_cost_cents);
    }
}
