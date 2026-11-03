<?php

namespace App\Http\Controllers\Api\V1;

use App\Calendar\WorkCalendars;
use App\Calendar\WorkingDays;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendário da obra: os dias de um mês (úteis, feriados, paragens) e o prazo em dias úteis.
 * Quem vê a obra vê o calendário.
 */
class ProjectCalendarController extends Controller
{
    /** Prazos maiores do que isto são quase de certeza datas erradas: não se contam dia a dia. */
    private const MAX_TERM_DAYS = 366 * 15;

    public function __invoke(Request $request, Project $project, WorkCalendars $calendars): JsonResponse
    {
        $this->authorize('projects.view', $project);

        $month = $request->validate(['month' => ['sometimes', 'date_format:Y-m']])['month'] ?? null;
        $tenant = $this->tenantContext()->tenantOrFail();
        $today = CarbonImmutable::now($tenant->settings['timezone'] ?? 'Europe/Lisbon')->startOfDay();
        $first = $month ? CarbonImmutable::createFromFormat('!Y-m', $month) : $today->startOfMonth();

        $calendar = $calendars->forProject($project);
        $days = WorkingDays::between($calendar, $first, $first->endOfMonth());

        return response()->json(['data' => [
            'calendar' => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'is_default' => $calendar->is_default,
                // false = a obra segue o calendário por omissão da empresa.
                'chosen' => $project->work_calendar_id !== null,
            ],
            'month' => $first->format('Y-m'),
            'today' => $today->toDateString(),
            'days' => $days->days(),
            'working_days' => $days->count(),
            'missing_holiday_years' => $days->yearsWithoutNationalHolidays(),
            'term' => $this->term($project, $calendar, $today),
        ]]);
    }

    /** @return array<string, mixed>|null */
    private function term(Project $project, WorkCalendar $calendar, CarbonImmutable $today): ?array
    {
        if (! $project->starts_on || ! $project->planned_end_on) {
            return null;
        }

        $start = CarbonImmutable::parse($project->starts_on->toDateString());
        $end = CarbonImmutable::parse($project->planned_end_on->toDateString());
        $calendarDays = (int) $start->diffInDays($end) + 1;

        if ($calendarDays > self::MAX_TERM_DAYS) {
            return ['calendar_days' => $calendarDays, 'working_days' => null, 'working_days_done' => null, 'working_days_left' => null, 'missing_holiday_years' => []];
        }

        $days = WorkingDays::between($calendar, $start, $end);

        return [
            'calendar_days' => $calendarDays,
            'working_days' => $days->count(),
            // Até hoje inclusive.
            'working_days_done' => $today->lt($start) ? 0 : $days->count($start, $today),
            'working_days_left' => $today->gte($end) ? 0 : $days->count($today->addDay()->max($start), $end),
            'missing_holiday_years' => $days->yearsWithoutNationalHolidays(),
        ];
    }
}
