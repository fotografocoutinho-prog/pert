<?php

namespace App\Planning;

use App\Calendar\WorkingDays;
use App\Models\Activity;
use App\Models\ActivityDependency;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Método do caminho crítico (CPM) em dias úteis, com precedências FS, SS, FF e SF e folgas
 * (positivas ou negativas). As datas saem dos dias úteis do calendário da obra; as atividades
 * resumo (com filhas) cobrem as datas das filhas e não entram na rede. PERT: duração esperada
 * (o + 4m + p) / 6 e variância ((p − o) / 6)² somadas ao longo do caminho crítico.
 */
final class Schedule
{
    /**
     * @param  Collection<int, Activity>  $activities  com predecessors carregados
     * @return array{
     *     activities: array<int, array<string, mixed>>, start_on: string|null, finish_on: string|null,
     *     duration_days: int, critical: list<int>, pert: array{expected_days: float, sigma_days: float}|null
     * }
     *
     * @throws ScheduleCycleException
     */
    public static function compute(Collection $activities, CarbonInterface $start, WorkCalendar $calendar): array
    {
        $byId = $activities->keyBy('id');
        $parents = $activities->pluck('parent_id')->filter()->unique()->flip();
        $leaves = $byId->reject(fn (Activity $a) => $parents->has($a->id));

        // Dias úteis carregados: as durações com folga, a contar da restrição "não antes de" mais tardia
        // (a previsão põe as atividades por fazer "não antes de hoje", que pode estar longe do início).
        $latest = $leaves->map(fn (Activity $a) => $a->start_no_earlier_than)->filter()->max();
        $offset = $latest ? max(0, (int) CarbonImmutable::parse($start->toDateString())->diffInDays($latest)) : 0;
        $days = self::workdays($calendar, $start, max(60, (int) ($leaves->sum('duration_days') * 1.6) + 30 + $offset));
        $firstDay = $days[0] ?? CarbonImmutable::parse($start->toDateString());
        $indexOf = fn (CarbonInterface $date) => self::indexOf($days, $date);

        // Ordem topológica (Kahn), só com folhas.
        $preds = [];
        $succs = [];
        foreach ($leaves as $id => $activity) {
            $preds[$id] = [];
            $succs[$id] ??= [];
            foreach ($activity->predecessors as $dep) {
                /** @var ActivityDependency $dep */
                if ($leaves->has($dep->predecessor_id)) {
                    $preds[$id][] = $dep;
                    $succs[$dep->predecessor_id][] = $dep;
                }
            }
        }
        $order = self::topological($leaves->keys()->all(), $preds);

        $d = fn (int $id) => max(0, (int) $leaves[$id]->duration_days);
        $es = [];
        $ef = [];
        foreach ($order as $id) {
            $activity = $leaves[$id];
            $value = $activity->start_no_earlier_than ? max(0, $indexOf($activity->start_no_earlier_than)) : 0;
            foreach ($preds[$id] as $dep) {
                $p = $dep->predecessor_id;
                $value = max($value, match ($dep->type) {
                    'SS' => $es[$p] + $dep->lag_days,
                    'FF' => $ef[$p] + $dep->lag_days - $d($id),
                    'SF' => $es[$p] + $dep->lag_days - $d($id),
                    default => $ef[$p] + $dep->lag_days,
                });
            }
            $es[$id] = max(0, $value);
            $ef[$id] = $es[$id] + $d($id);
        }

        $finish = $ef === [] ? 0 : max($ef);
        $lf = [];
        $ls = [];
        foreach (array_reverse($order) as $id) {
            $value = $finish;
            foreach ($succs[$id] ?? [] as $dep) {
                $s = $dep->successor_id;
                $value = min($value, match ($dep->type) {
                    'SS' => $ls[$s] - $dep->lag_days + $d($id),
                    'FF' => $lf[$s] - $dep->lag_days,
                    'SF' => $lf[$s] - $dep->lag_days + $d($id),
                    default => $ls[$s] - $dep->lag_days,
                });
            }
            $lf[$id] = $value;
            $ls[$id] = $value - $d($id);
        }

        $out = [];
        foreach ($leaves as $id => $activity) {
            $out[$id] = self::present($activity, $es[$id], $ef[$id], $ls[$id] - $es[$id], $days);
        }

        // Resumos: das filhas (recursivo, de baixo para cima).
        $children = $activities->groupBy('parent_id');
        $summary = function (int $id) use (&$summary, $children, &$out, $byId, $days): ?array {
            if (isset($out[$id])) {
                return $out[$id];
            }
            $rows = collect($children[$id] ?? [])->map(fn (Activity $c) => $summary($c->id))->filter();
            if ($rows->isEmpty()) {
                return null;
            }
            $weight = $rows->sum(fn ($r) => max(1, $r['duration_days']));
            $progress = (int) round($rows->sum(fn ($r) => $r['progress_pct'] * max(1, $r['duration_days'])) / $weight);
            $row = self::present($byId[$id], $rows->min('es'), $rows->max('ef'), $rows->min('total_float'), $days, true);
            $row['progress_pct'] = $progress;

            return $out[$id] = $row;
        };
        foreach ($parents->keys() as $id) {
            if ($byId->has($id)) {
                $summary((int) $id);
            }
        }

        $critical = collect($out)->filter(fn ($r) => $r['critical'] && ! $r['summary'])->keys()->values()->all();

        return [
            'activities' => $out,
            'start_on' => $leaves->isEmpty() ? null : $firstDay->toDateString(),
            'finish_on' => $leaves->isEmpty() ? null : self::dateAt($days, max(0, $finish - 1))->toDateString(),
            'duration_days' => $finish,
            'critical' => $critical,
            // toBase(): o only() de uma coleção Eloquent filtra pela chave primária e perde as chaves.
            'pert' => self::pert($leaves->toBase()->only($critical)),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, list<ActivityDependency>>  $preds
     * @return list<int>
     */
    private static function topological(array $ids, array $preds): array
    {
        $indegree = [];
        $next = [];
        foreach ($ids as $id) {
            $indegree[$id] = count($preds[$id]);
            foreach ($preds[$id] as $dep) {
                $next[$dep->predecessor_id][] = $id;
            }
        }
        $queue = array_values(array_filter($ids, fn ($id) => $indegree[$id] === 0));
        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($next[$id] ?? [] as $s) {
                if (--$indegree[$s] === 0) {
                    $queue[] = $s;
                }
            }
        }
        if (count($order) !== count($ids)) {
            throw new ScheduleCycleException('As precedências fazem um ciclo.');
        }

        return $order;
    }

    /** Uma precedência nova fecharia um ciclo? (o sucessor já chega ao predecessor) */
    public static function wouldCycle(int $predecessorId, int $successorId, Collection $dependencies): bool
    {
        if ($predecessorId === $successorId) {
            return true;
        }
        $next = $dependencies->groupBy('predecessor_id');
        $stack = [$successorId];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === $predecessorId) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($next[$id] ?? [] as $dep) {
                $stack[] = $dep->successor_id;
            }
        }

        return false;
    }

    /** @param  list<CarbonImmutable>  $days */
    private static function present(Activity $activity, int $es, int $ef, int $float, array $days, bool $summary = false): array
    {
        $duration = $ef - $es;
        $hasPert = $activity->optimistic_days !== null && $activity->likely_days !== null && $activity->pessimistic_days !== null;

        return [
            'id' => $activity->id,
            'summary' => $summary,
            'es' => $es,
            'ef' => $ef,
            'total_float' => $float,
            'critical' => $float <= 0,
            'duration_days' => $duration,
            'start_on' => self::dateAt($days, $es)->toDateString(),
            'finish_on' => self::dateAt($days, max($es, $ef - 1))->toDateString(),
            'progress_pct' => (int) $activity->progress_pct,
            'pert_expected_days' => $hasPert ? round(((float) $activity->optimistic_days + 4 * (float) $activity->likely_days + (float) $activity->pessimistic_days) / 6, 2) : null,
        ];
    }

    /** @param  Collection<int, Activity>  $critical */
    private static function pert(Collection $critical): ?array
    {
        $withPert = $critical->filter(fn (Activity $a) => $a->optimistic_days !== null && $a->likely_days !== null && $a->pessimistic_days !== null);
        if ($withPert->isEmpty()) {
            return null;
        }
        $expected = 0.0;
        $variance = 0.0;
        foreach ($critical as $a) {
            if ($withPert->has($a->id)) {
                $o = (float) $a->optimistic_days;
                $m = (float) $a->likely_days;
                $p = (float) $a->pessimistic_days;
                $expected += ($o + 4 * $m + $p) / 6;
                $variance += (($p - $o) / 6) ** 2;
            } else {
                $expected += (float) $a->duration_days;
            }
        }

        return ['expected_days' => round($expected, 1), 'sigma_days' => round(sqrt($variance), 1)];
    }

    /** @return list<CarbonImmutable> */
    private static function workdays(WorkCalendar $calendar, CarbonInterface $start, int $needed): array
    {
        $from = CarbonImmutable::parse($start->toDateString());
        // Margem larga para fins de semana e feriados; cresce se não chegar.
        for ($span = (int) ceil($needed * 1.6) + 14; ; $span *= 2) {
            $working = WorkingDays::between($calendar, $from, $from->addDays($span));
            $days = [];
            for ($day = $from, $i = 0; $i <= $span; $day = $day->addDay(), $i++) {
                if ($working->isWorking($day)) {
                    $days[] = $day;
                }
            }
            if (count($days) >= $needed || $span > 20000) {
                return $days;
            }
        }
    }

    /** @param  list<CarbonImmutable>  $days */
    private static function dateAt(array $days, int $index): CarbonImmutable
    {
        if ($days === []) {
            return CarbonImmutable::today();
        }
        if ($index < count($days)) {
            return $days[max(0, $index)];
        }

        // Para lá do intervalo carregado: conta dias de semana a partir do último.
        $day = end($days);
        for ($i = count($days) - 1; $i < $index; $i++) {
            $day = $day->addWeekday();
        }

        return $day;
    }

    /** Índice do primeiro dia útil na data ou depois dela. @param  list<CarbonImmutable>  $days */
    private static function indexOf(array $days, CarbonInterface $date): int
    {
        $target = $date->toDateString();
        foreach ($days as $i => $day) {
            if ($day->toDateString() >= $target) {
                return $i;
            }
        }

        return count($days);
    }
}
