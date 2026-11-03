<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calendário de trabalho: horas por dia da semana (chaves ISO "1" = segunda … "7" = domingo;
 * 0 = não se trabalha) e dias sem trabalho (feriados, paragens). Contas em App\Calendar\WorkingDays.
 */
#[Fillable(['name', 'weekly_hours'])]
class WorkCalendar extends Model
{
    use BelongsToTenant;

    /** Segunda a sexta, 8 horas. */
    public const STANDARD_WEEK = ['1' => 8, '2' => 8, '3' => 8, '4' => 8, '5' => 8, '6' => 0, '7' => 0];

    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Horas num dia da semana ISO (1 = segunda). */
    public function hoursOn(int $isoWeekday): float
    {
        return (float) ($this->weekly_hours[(string) $isoWeekday] ?? 0);
    }
}
