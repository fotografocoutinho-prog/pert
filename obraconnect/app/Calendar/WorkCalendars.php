<?php

namespace App\Calendar;

use App\Enums\HolidayKind;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\WorkCalendar;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Calendários da empresa ativa. Cada empresa tem um calendário por omissão (segunda a sexta,
 * com os feriados nacionais deste ano e do seguinte), criado no registo ou, para empresas
 * anteriores a esta funcionalidade, na primeira vez que é preciso.
 */
class WorkCalendars
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function default(): WorkCalendar
    {
        return WorkCalendar::query()->where('is_default', true)->first()
            ?? DB::transaction(function (): WorkCalendar {
                // Dois pedidos ao mesmo tempo não criam dois calendários por omissão.
                Tenant::query()->whereKey($this->tenants->id())->lockForUpdate()->first();

                return WorkCalendar::query()->where('is_default', true)->first() ?? $this->createDefault();
            });
    }

    public function forProject(Project $project): WorkCalendar
    {
        return $project->work_calendar_id ? $project->workCalendar : $this->default();
    }

    public function createDefault(): WorkCalendar
    {
        $calendar = new WorkCalendar(['name' => 'Calendário geral', 'weekly_hours' => WorkCalendar::STANDARD_WEEK]);
        $calendar->is_default = true;
        $calendar->save();

        $year = (int) now()->year;
        $this->addNationalHolidays($calendar, $year);
        $this->addNationalHolidays($calendar, $year + 1);

        return $calendar;
    }

    /** Junta os feriados nacionais do ano que faltem (e o Carnaval, se pedido). Devolve quantos juntou. */
    public function addNationalHolidays(WorkCalendar $calendar, int $year, bool $carnival = false): int
    {
        $wanted = collect(PortugueseHolidays::national($year))
            ->map(fn (string $name, string $date) => ['date' => $date, 'name' => $name, 'kind' => HolidayKind::National]);
        if ($carnival) {
            $wanted->put(PortugueseHolidays::carnival($year), ['date' => PortugueseHolidays::carnival($year), 'name' => 'Carnaval', 'kind' => HolidayKind::Optional]);
        }

        $existing = $calendar->holidays()->whereYear('date', $year)->pluck('date')->map->toDateString()->all();
        $added = 0;
        foreach ($wanted->except($existing) as $holiday) {
            $calendar->holidays()->create($holiday);
            $added++;
        }

        return $added;
    }

    public function makeDefault(WorkCalendar $calendar): void
    {
        DB::transaction(function () use ($calendar): void {
            WorkCalendar::query()->whereKeyNot($calendar->getKey())->where('is_default', true)->update(['is_default' => false]);
            $calendar->forceFill(['is_default' => true])->save();
        });
    }
}
