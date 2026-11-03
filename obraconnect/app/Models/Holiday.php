<?php

namespace App\Models;

use App\Enums\HolidayKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dia sem trabalho num calendário (feriado ou paragem da empresa). */
#[Fillable(['date', 'name', 'kind'])]
class Holiday extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => HolidayKind::class,
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'work_calendar_id');
    }
}
