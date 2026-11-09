<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotografia semanal das previsões de uma obra (custo no fim e fim da obra). Uma por obra e por
 * semana; a da semana em curso é atualizada todos os dias pelo obraconnect:forecast-watch.
 */
class ForecastSnapshot extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'taken_on' => 'date',
            'cost_available' => 'boolean',
            'bac_cents' => 'integer',
            'forecast_cents' => 'integer',
            'ceiling_cents' => 'integer',
            'actual_cents' => 'integer',
            'committed_cents' => 'integer',
            'sale_cents' => 'integer',
            'cost_progress_pct' => 'float',
            'margin_pct' => 'float',
            'schedule_available' => 'boolean',
            'forecast_finish_on' => 'date',
            'planned_finish_on' => 'date',
            'contract_end_on' => 'date',
            'delay_plan_days' => 'integer',
            'delay_contract_days' => 'integer',
            'schedule_progress_pct' => 'float',
            'planned_progress_pct' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
