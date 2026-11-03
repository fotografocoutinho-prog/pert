<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Calendar\PortugueseHolidays;
use App\Calendar\WorkCalendars;
use App\Enums\HolidayKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Calendários de trabalho da empresa e os seus dias sem trabalho (feriados e paragens).
 * Consultar: quem vê a empresa ou escolhe o calendário de obras. Alterar: calendars.manage.
 */
class WorkCalendarController extends Controller
{
    /** Um período (ex.: férias coletivas) cria um dia por dia útil; limite para evitar enganos. */
    private const MAX_PERIOD_DAYS = 62;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly WorkCalendars $calendars,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorizeView();
        $this->calendars->default();

        $calendars = WorkCalendar::query()
            ->withCount('projects')
            ->with(['holidays' => fn ($q) => $q->where('kind', HolidayKind::National->value)->select(['id', 'work_calendar_id', 'date'])])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $calendars->map(fn (WorkCalendar $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('calendars.manage');
        $data = $this->validated($request);

        $calendar = DB::transaction(function () use ($data, $request): WorkCalendar {
            $calendar = new WorkCalendar(Arr::only($data, ['name', 'weekly_hours']));
            $calendar->created_by_user_id = $request->user()->id;
            $calendar->save();

            if ($data['with_national_holidays'] ?? true) {
                $year = (int) now()->year;
                $this->calendars->addNationalHolidays($calendar, $year);
                $this->calendars->addNationalHolidays($calendar, $year + 1);
            }

            return $calendar;
        });

        $this->auditor->record('calendar.created', $calendar, ['name' => $calendar->name]);

        return response()->json(['data' => $this->present($calendar->loadCount('projects'))], 201);
    }

    public function update(Request $request, WorkCalendar $workCalendar): JsonResponse
    {
        $this->authorize('calendars.manage');
        $data = $this->validated($request, $workCalendar);

        $workCalendar->update(Arr::only($data, ['name', 'weekly_hours']));
        $changes = $this->auditor->changes($workCalendar);
        if ($changes['new'] !== []) {
            $this->auditor->record('calendar.updated', $workCalendar, $changes);
        }

        if (($data['is_default'] ?? false) && ! $workCalendar->is_default) {
            $this->calendars->makeDefault($workCalendar);
            $this->auditor->record('calendar.made_default', $workCalendar, ['name' => $workCalendar->name]);
        }

        return response()->json(['data' => $this->present($workCalendar->loadCount('projects'))]);
    }

    public function destroy(WorkCalendar $workCalendar): Response
    {
        $this->authorize('calendars.manage');

        if ($workCalendar->is_default) {
            throw ApiException::conflict('O calendário por omissão não se apaga. Escolha outro como calendário por omissão primeiro.', 'CALENDAR_DEFAULT');
        }
        if ($workCalendar->projects()->exists()) {
            throw ApiException::conflict('Há obras com este calendário. Mude-as para outro calendário primeiro.', 'CALENDAR_IN_USE');
        }

        $workCalendar->delete();
        $this->auditor->record('calendar.deleted', null, ['id' => $workCalendar->id, 'name' => $workCalendar->name]);

        return response()->noContent();
    }

    public function holidays(Request $request, WorkCalendar $workCalendar): JsonResponse
    {
        $this->authorizeView();
        $year = (int) ($request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);

        return response()->json(['data' => $workCalendar->holidays()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => $this->presentHoliday($h))]);
    }

    /** Um dia, ou um período (date … until) em que só os dias de trabalho ficam marcados. */
    public function storeHolidays(Request $request, WorkCalendar $workCalendar): JsonResponse
    {
        $this->authorize('calendars.manage');

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(HolidayKind::class)],
        ], [], ['date' => 'data', 'until' => 'até', 'name' => 'nome', 'kind' => 'tipo']);

        $from = CarbonImmutable::parse($data['date']);
        $until = CarbonImmutable::parse($data['until'] ?? $data['date']);
        if ($from->diffInDays($until) + 1 > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['until' => 'Um período tem no máximo '.self::MAX_PERIOD_DAYS.' dias.']);
        }

        $taken = $workCalendar->holidays()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $until->toDateString())
            ->pluck('date')->map->toDateString()->all();
        $isPeriod = ! $from->equalTo($until);

        $created = DB::transaction(function () use ($from, $until, $taken, $isPeriod, $workCalendar, $data) {
            $created = collect();
            foreach (CarbonPeriod::create($from, $until) as $day) {
                // Num período, sábados e domingos (ou outros dias sem horas) já não são de trabalho.
                if (in_array($day->toDateString(), $taken, true) || ($isPeriod && $workCalendar->hoursOn($day->isoWeekday()) <= 0)) {
                    continue;
                }
                $created->push($workCalendar->holidays()->create([
                    'date' => $day->toDateString(),
                    'name' => trim($data['name']),
                    'kind' => $data['kind'],
                ]));
            }

            return $created;
        });

        if ($created->isEmpty()) {
            throw ValidationException::withMessages(['date' => $isPeriod
                ? 'Nesse período não há dias de trabalho por marcar.'
                : 'Esse dia já está marcado neste calendário.']);
        }

        $this->auditor->record('calendar.days_off_added', $workCalendar, [
            'name' => trim($data['name']), 'kind' => $data['kind'], 'from' => $from->toDateString(), 'until' => $until->toDateString(), 'days' => $created->count(),
        ]);

        return response()->json(['data' => $created->map(fn (Holiday $h) => $this->presentHoliday($h))->values()], 201);
    }

    public function destroyHolidays(Request $request, WorkCalendar $workCalendar): Response
    {
        $this->authorize('calendars.manage');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:400'], 'ids.*' => ['integer']])['ids'];

        $holidays = $workCalendar->holidays()->whereIn('id', $ids)->orderBy('date')->get();
        if ($holidays->isNotEmpty()) {
            $workCalendar->holidays()->whereIn('id', $holidays->modelKeys())->delete();
            $this->auditor->record('calendar.days_off_removed', $workCalendar, [
                'days' => $holidays->map(fn (Holiday $h) => $h->date->toDateString().' '.$h->name)->all(),
            ]);
        }

        return response()->noContent();
    }

    public function addNationalHolidays(Request $request, WorkCalendar $workCalendar): JsonResponse
    {
        $this->authorize('calendars.manage');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:'.PortugueseHolidays::FIRST_YEAR, 'max:'.PortugueseHolidays::LAST_YEAR],
            'carnival' => ['sometimes', 'boolean'],
        ], [], ['year' => 'ano']);

        $added = DB::transaction(fn () => $this->calendars->addNationalHolidays($workCalendar, (int) $data['year'], (bool) ($data['carnival'] ?? false)));
        if ($added > 0) {
            $this->auditor->record('calendar.national_holidays_added', $workCalendar, ['year' => (int) $data['year'], 'days' => $added]);
        }

        return response()->json(['data' => ['added' => $added]]);
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::any(['company.view', 'calendars.manage', 'projects.create', 'projects.update']), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?WorkCalendar $calendar = null): array
    {
        $required = $calendar ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'weekly_hours' => [$required, 'array:1,2,3,4,5,6,7'],
            'weekly_hours.*' => ['numeric', 'min:0', 'max:24'],
            'is_default' => $calendar ? ['sometimes', 'accepted'] : ['prohibited'],
            'with_national_holidays' => $calendar ? ['prohibited'] : ['sometimes', 'boolean'],
        ], [], ['name' => 'nome', 'weekly_hours' => 'horas por dia']);

        if (isset($data['weekly_hours'])) {
            $week = [];
            foreach (range(1, 7) as $day) {
                $week[(string) $day] = round((float) ($data['weekly_hours'][$day] ?? $data['weekly_hours'][(string) $day] ?? 0) * 2) / 2;
            }
            if (array_sum($week) <= 0) {
                throw ValidationException::withMessages(['weekly_hours' => 'Indique pelo menos um dia de trabalho na semana.']);
            }
            $data['weekly_hours'] = $week;
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(WorkCalendar $calendar): array
    {
        $years = $calendar->relationLoaded('holidays')
            ? $calendar->holidays->map(fn (Holiday $h) => $h->date->year)->unique()->sort()->values()->all()
            : $calendar->holidays()->where('kind', HolidayKind::National->value)->pluck('date')->map->year->unique()->sort()->values()->all();

        return [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'weekly_hours' => (object) collect(range(1, 7))->mapWithKeys(fn (int $d) => [(string) $d => $calendar->hoursOn($d)])->all(),
            'weekly_total' => array_sum(array_map(fn (int $d) => $calendar->hoursOn($d), range(1, 7))),
            'is_default' => $calendar->is_default,
            'projects_count' => $calendar->projects_count ?? null,
            // Anos com feriados nacionais no calendário (a interface avisa quando falta o ano seguinte).
            'national_holiday_years' => $years,
        ];
    }

    /** @return array<string, mixed> */
    private function presentHoliday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'kind' => $holiday->kind->value,
            'kind_label' => $holiday->kind->label(),
        ];
    }
}
