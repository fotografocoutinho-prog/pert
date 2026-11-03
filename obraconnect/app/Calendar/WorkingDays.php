<?php

namespace App\Calendar;

use App\Models\Holiday;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Contas de dias úteis num calendário, num intervalo de datas. Os dias sem trabalho do
 * intervalo são lidos de uma vez, por isso cria-se uma instância por intervalo.
 */
final class WorkingDays
{
    /** @param  Collection<string, Holiday>  $offDays  'Y-m-d' => dia sem trabalho */
    private function __construct(
        public readonly WorkCalendar $calendar,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly Collection $offDays,
    ) {}

    public static function between(WorkCalendar $calendar, CarbonInterface $from, CarbonInterface $to): self
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        // whereDate e não whereBetween: o SQLite guarda a data com a hora ("2026-01-01 00:00:00").
        $offDays = $calendar->holidays()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (Holiday $h) => $h->date->toDateString());

        return new self($calendar, $from, $to, $offDays);
    }

    public function holiday(CarbonInterface $date): ?Holiday
    {
        return $this->offDays->get($date->toDateString());
    }

    /** Horas de trabalho no dia (0 em feriados, paragens e dias da semana sem trabalho). */
    public function hours(CarbonInterface $date): float
    {
        return $this->holiday($date) ? 0.0 : $this->calendar->hoursOn($date->isoWeekday());
    }

    public function isWorking(CarbonInterface $date): bool
    {
        return $this->hours($date) > 0;
    }

    /** Dias úteis entre duas datas, ambas incluídas (limitado ao intervalo carregado). */
    public function count(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        $from = CarbonImmutable::parse(($from ?? $this->from)->toDateString())->max($this->from);
        $to = CarbonImmutable::parse(($to ?? $this->to)->toDateString())->min($this->to);

        $count = 0;
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $count += $this->isWorking($day) ? 1 : 0;
        }

        return $count;
    }

    /**
     * Dia a dia, para a interface.
     *
     * @return list<array{date: string, weekday: int, working: bool, hours: float, holiday: array{name: string, kind: string, kind_label: string}|null}>
     */
    public function days(): array
    {
        $days = [];
        foreach (CarbonPeriod::create($this->from, $this->to) as $day) {
            $holiday = $this->holiday($day);
            $hours = $this->hours($day);
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->isoWeekday(),
                'working' => $hours > 0,
                'hours' => $hours,
                'holiday' => $holiday ? ['name' => $holiday->name, 'kind' => $holiday->kind->value, 'kind_label' => $holiday->kind->label()] : null,
            ];
        }

        return $days;
    }

    /**
     * Anos do intervalo sem nenhum feriado nacional no calendário (as contas desses anos
     * contam feriados como dias úteis: a interface avisa).
     *
     * @return list<int>
     */
    public function yearsWithoutNationalHolidays(): array
    {
        $covered = $this->offDays
            ->filter(fn (Holiday $h) => $h->kind->value === 'national')
            ->map(fn (Holiday $h) => $h->date->year)
            ->unique()
            ->all();

        return array_values(array_diff(range($this->from->year, $this->to->year), $covered));
    }
}
