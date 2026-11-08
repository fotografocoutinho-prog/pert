<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manutenção de um equipamento.
 */
#[Fillable(['performed_on', 'description', 'cost_cents', 'next_due_on'])]
class MaintenanceRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_records';

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'next_due_on' => 'date',
            'cost_cents' => 'integer',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
