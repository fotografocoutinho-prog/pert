<?php

namespace App\Forecasting;

use App\Calendar\WorkingDays;
use App\Models\Activity;
use App\Models\Inspection;
use App\Models\Project;
use App\Models\WorkCalendar;
use App\Planning\Schedule;
use App\Planning\ScheduleCycleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Previsão do fim da obra, explicada. Sem IA: o planeamento (CPM) recalculado à data de hoje.
 *
 * - Plano: o CPM das atividades tal como estão (durações e precedências), a partir do início da obra.
 * - Previsão: o mesmo CPM, mas o que está feito já não ocupa tempo, o que está em curso só ocupa o
 *   que lhe falta (duração × % por fazer) e nada do que falta começa antes de hoje.
 * - Planeado para hoje: quanto de cada atividade devia estar feito pelo plano, pesado pela duração.
 * - As razões apontam as atividades do caminho crítico atrasadas (são elas que mexem no fim da obra)
 *   e os pontos de paragem por libertar.
 */
final class ScheduleForecast
{
    /** Pontos percentuais abaixo do planeado para uma atividade contar como atrasada. */
    public const BEHIND_POINTS = 10;

    /** @return array<string, mixed> */
    public static function for(Project $project, CarbonImmutable $today, WorkCalendar $calendar): array
    {
        $contractEnd = $project->planned_end_on?->toDateString();
        $activities = $project->activities()
            ->with(['predecessors', 'inspections' => fn ($q) => $q->holdingWork()->select(['id', 'number', 'title', 'activity_id'])])
            ->orderBy('sort_order')->orderBy('id')->get();
        if ($activities->isEmpty()) {
            return ['available' => false, 'message' => 'Sem planeamento: crie as atividades da obra no separador Planeamento para haver previsão do fim.', 'contract_end_on' => $contractEnd];
        }

        $start = CarbonImmutable::parse(($project->starts_on ?? $project->created_at ?? $today)->toDateString());
        try {
            $plan = Schedule::compute($activities, $start, $calendar);
        } catch (ScheduleCycleException $e) {
            return ['available' => false, 'message' => $e->getMessage().' Corrija o planeamento para haver previsão do fim.', 'contract_end_on' => $contractEnd];
        }

        $parents = $activities->pluck('parent_id')->filter()->unique()->flip();
        /** @var Collection<int, Activity> $leaves */
        $leaves = $activities->reject(fn (Activity $a) => $parents->has($a->id))->keyBy('id');
        $started = $today->greaterThanOrEqualTo($start);
        $finished = $leaves->every(fn (Activity $a) => $a->progress_pct >= 100);

        if (! $started || $finished) {
            $forecast = $plan;
        } else {
            $updated = $activities->map(function (Activity $a) use ($leaves, $today): Activity {
                if (! $leaves->has($a->id)) {
                    return $a;
                }
                $copy = clone $a;
                $done = (int) $a->progress_pct;
                if ($done >= 100) {
                    $copy->duration_days = 0;
                    $copy->start_no_earlier_than = null;

                    return $copy;
                }
                if ($done > 0) {
                    $copy->duration_days = max(1, (int) ceil($a->duration_days * (100 - $done) / 100));
                }
                $copy->start_no_earlier_than = $a->start_no_earlier_than !== null && $a->start_no_earlier_than->toDateString() > $today->toDateString()
                    ? $a->start_no_earlier_than : $today;

                return $copy;
            });
            $forecast = Schedule::compute($updated, $start, $calendar);
        }

        // Dia útil de hoje contado desde o início (o índice dos dias do CPM).
        $firstDay = CarbonImmutable::parse($plan['start_on'] ?? $start->toDateString());
        $todayIndex = $today->greaterThan($firstDay) ? WorkingDays::between($calendar, $firstDay, $today->subDay())->count() : 0;

        $weight = 0;
        $plannedSum = 0.0;
        $doneSum = 0.0;
        $rows = [];
        foreach ($leaves as $id => $a) {
            $p = $plan['activities'][$id];
            $f = $forecast['activities'][$id];
            $w = max(1, (int) $a->duration_days);
            $span = $p['ef'] - $p['es'];
            $plannedFraction = $span <= 0 ? ($todayIndex >= $p['es'] ? 1.0 : 0.0) : min(1.0, max(0.0, ($todayIndex - $p['es']) / $span));
            $weight += $w;
            $plannedSum += $w * $plannedFraction;
            $doneSum += $w * min(100, (int) $a->progress_pct) / 100;

            $done = (int) $a->progress_pct;
            if ($done >= 100) {
                continue;
            }
            $plannedPct = (int) round($plannedFraction * 100);
            $rows[] = [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'progress_pct' => $done,
                'planned_pct' => $plannedPct,
                'planned_start_on' => $p['start_on'],
                'planned_finish_on' => $p['finish_on'],
                'forecast_start_on' => $f['start_on'],
                'forecast_finish_on' => $f['finish_on'],
                'slip_days' => $f['ef'] - $p['ef'],
                'critical' => (bool) $f['critical'],
                'late_start' => $started && $done === 0 && $p['start_on'] < $today->toDateString(),
                'behind' => $started && $plannedPct - $done >= self::BEHIND_POINTS,
                // À espera de uma anterior por acabar (FS/FF) ou por começar (SS/SF): o atraso não é dela.
                'waiting' => $a->predecessors->contains(fn ($dep) => $leaves->has($dep->predecessor_id)
                    && (in_array($dep->type, ['SS', 'SF'], true) ? (int) $leaves[$dep->predecessor_id]->progress_pct === 0 : (int) $leaves[$dep->predecessor_id]->progress_pct < 100)),
                'holds' => $a->inspections->map(fn (Inspection $i) => ['id' => $i->id, 'number' => $i->number])->values()->all(),
            ];
        }
        $plannedProgress = $weight > 0 ? round($plannedSum * 100 / $weight, 1) : 0.0;
        $progress = $weight > 0 ? round($doneSum * 100 / $weight, 1) : 0.0;

        $planFinish = $plan['finish_on'];
        $forecastFinish = $finished
            ? ($leaves->map(fn (Activity $a) => $a->completed_at?->toDateString())->filter()->max() ?? $planFinish)
            : $forecast['finish_on'];
        $delayPlan = $finished ? null : $forecast['duration_days'] - $plan['duration_days'];
        $delayContract = $contractEnd !== null && $forecastFinish !== null
            ? (int) CarbonImmutable::parse($contractEnd)->diffInDays(CarbonImmutable::parse($forecastFinish), false)
            : null;

        $late = collect($rows)->filter(fn ($r) => $r['slip_days'] > 0 || $r['late_start'] || $r['behind']);
        // As causas: atrasadas por si (não à espera de outra) e no caminho crítico da previsão.
        $drivers = $late->filter(fn ($r) => $r['critical'] && $r['slip_days'] > 0 && ! $r['waiting'] && ($r['late_start'] || $r['behind']))->sortByDesc('slip_days')->values();
        $offPath = $late->filter(fn ($r) => ! $r['critical'] && ! $r['waiting'] && ($r['late_start'] || $r['behind']));

        $reasons = [];
        $date = fn (?string $d) => Explain::date($d);

        if ($finished) {
            $reasons[] = Explain::reason('good', 'Todas as atividades estão concluídas'.($forecastFinish ? ' (a última a '.$date($forecastFinish).').' : '.'), 'planeamento');
        } elseif (! $started) {
            $reasons[] = Explain::reason('info', 'A obra começa a '.$date($start->toDateString()).': ainda não há avanço para comparar, o fim previsto é o do planeamento.', 'planeamento');
        }

        if ($delayContract !== null) {
            if ($delayContract > 0) {
                $reasons[] = Explain::reason('danger', 'Fim previsto a '.$date($forecastFinish).': '.Explain::days($delayContract).' depois do prazo contratual ('.$date($contractEnd).').', 'dados');
            } else {
                $reasons[] = Explain::reason('good', 'Fim previsto a '.$date($forecastFinish).': dentro do prazo contratual ('.$date($contractEnd).')'.($delayContract < 0 ? ', com '.Explain::days($delayContract).' de folga.' : ', no próprio dia.'), 'dados');
            }
        } elseif (! $finished) {
            $reasons[] = Explain::reason('info', 'A obra não tem data de fim contratual: a previsão só se compara com o planeamento. Pode indicá-la em "Dados da obra".', 'dados');
        }

        if ($started && ! $finished && $delayPlan !== null) {
            if ($delayPlan > 0) {
                $reasons[] = Explain::reason('warning', 'O planeamento previa acabar a '.$date($planFinish).'; com o avanço de hoje acaba '.Explain::days($delayPlan, true).' mais tarde.', 'planeamento');
            } elseif ($delayPlan < 0) {
                $reasons[] = Explain::reason('good', 'Adiantada: com o avanço de hoje acaba '.Explain::days($delayPlan, true).' antes do planeado ('.$date($planFinish).').', 'planeamento');
            } else {
                $reasons[] = Explain::reason('good', 'O fim previsto coincide com o planeamento ('.$date($planFinish).').', 'planeamento');
            }

            $gap = $progress - $plannedProgress;
            $text = 'Para hoje estava planeado '.Explain::pct($plannedProgress, 0).' do trabalho (pesado pela duração das atividades); está feito '.Explain::pct($progress, 0).'.';
            $reasons[] = Explain::reason($gap <= -5 ? 'warning' : ($gap >= 5 ? 'good' : 'info'), $text, 'planeamento');
        }

        foreach ($drivers->take(3) as $r) {
            $name = "‘{$r['code']} {$r['name']}’";
            $text = $r['late_start']
                ? "{$name} devia ter começado a {$date($r['planned_start_on'])} e ainda não começou"
                : "{$name} está a {$r['progress_pct']}% e devia estar a {$r['planned_pct']}%";
            $text .= '; está no caminho crítico, por isso atrasa o fim da obra: acaba a '.$date($r['forecast_finish_on']).' em vez de '.$date($r['planned_finish_on']).' (+'.Explain::days($r['slip_days'], true).').';
            $reasons[] = Explain::reason('warning', $text, 'planeamento');
        }
        if ($drivers->count() > 3) {
            $reasons[] = Explain::reason('warning', 'E mais '.($drivers->count() - 3).' atividades do caminho crítico atrasadas.', 'planeamento');
        }
        if ($offPath->isNotEmpty()) {
            $n = $offPath->count();
            $reasons[] = Explain::reason('info', $n.' '.($n === 1 ? 'atividade atrasada' : 'atividades atrasadas').' fora do caminho crítico: por agora não mexem no fim da obra (ainda têm folga).', 'planeamento');
        }
        foreach (collect($rows)->filter(fn ($r) => $r['holds'] !== []) as $r) {
            $numbers = implode(', ', array_map(fn ($h) => 'n.º '.$h['number'], $r['holds']));
            $reasons[] = Explain::reason('warning', "‘{$r['code']} {$r['name']}’ está parada à espera da libertação do ponto de paragem (inspeção {$numbers}).", 'inspecoes');
        }

        $status = match (true) {
            $finished => 'finished',
            $delayContract !== null && $delayContract > 0 => 'late',
            $delayPlan !== null && $delayPlan > 0 => 'behind',
            default => 'on_track',
        };

        return [
            'available' => true,
            'started' => $started,
            'finished' => $finished,
            'status' => $status,
            'start_on' => $start->toDateString(),
            'planned_finish_on' => $planFinish,
            'forecast_finish_on' => $forecastFinish,
            'contract_end_on' => $contractEnd,
            'delay_plan_days' => $delayPlan,
            'delay_contract_days' => $delayContract,
            'planned_progress_pct' => $plannedProgress,
            'progress_pct' => $progress,
            // Primeiro as atrasadas por si (as causas), depois as do caminho crítico, as mais empurradas antes.
            'activities' => $late->sortBy([
                fn ($a, $b) => self::ownDelay($b) <=> self::ownDelay($a),
                fn ($a, $b) => $b['critical'] <=> $a['critical'],
                fn ($a, $b) => $b['slip_days'] <=> $a['slip_days'],
            ])->take(15)->values()->all(),
            'reasons' => Explain::sort($reasons),
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function ownDelay(array $row): bool
    {
        return ! $row['waiting'] && ($row['late_start'] || $row['behind']);
    }
}
