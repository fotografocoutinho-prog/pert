<?php

namespace App\Http\Controllers\Api\V1;

use App\Alerts\ForecastWatch;
use App\Calendar\WorkCalendars;
use App\Forecasting\CostForecast;
use App\Forecasting\ScheduleForecast;
use App\Http\Controllers\Controller;
use App\Models\ForecastSnapshot;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Previsões explicadas da obra: custo no fim (quem vê custos: costs.view, costs.manage ou
 * finance.view) e fim da obra (quem vê o planeamento: planning.view ou planning.manage). Cada parte
 * só aparece a quem tem acesso a ela; sem nenhuma das duas, 403. O histórico são as fotografias
 * semanais (ForecastWatch) das semanas anteriores mais o valor de hoje.
 */
class ForecastController extends Controller
{
    /** Semanas de histórico devolvidas (cerca de meio ano). */
    public const HISTORY_WEEKS = 26;

    public function __construct(private readonly WorkCalendars $calendars) {}

    public function show(Project $project): JsonResponse
    {
        $canCost = Gate::any(['costs.view', 'costs.manage', 'finance.view'], $project);
        $canSchedule = Gate::any(['planning.view', 'planning.manage'], $project);
        abort_unless($canCost || $canSchedule, 403);
        $today = CarbonImmutable::today('Europe/Lisbon');

        $cost = $canCost ? CostForecast::for($project, $today) : null;
        $schedule = $canSchedule ? ScheduleForecast::for($project, $today, $this->calendars->forProject($project)) : null;
        $week = ForecastWatch::weekStart($today)->toDateString();
        $past = ForecastSnapshot::query()->where('project_id', $project->id)->where('week_start', '<', $week)
            ->orderByDesc('week_start')->limit(self::HISTORY_WEEKS - 1)->get()->reverse()->values();

        return response()->json(['data' => [
            'as_of' => $today->toDateString(),
            'cost' => $cost,
            'schedule' => $schedule,
            'history' => [
                'cost' => $cost !== null ? $this->costHistory($past, $cost, $week, $today) : null,
                'schedule' => $schedule !== null ? $this->scheduleHistory($past, $schedule, $week, $today) : null,
            ],
        ]]);
    }

    /** @param  Collection<int, ForecastSnapshot>  $past */
    private function costHistory(Collection $past, array $cost, string $week, CarbonImmutable $today): array
    {
        $rows = $past->filter(fn (ForecastSnapshot $s) => $s->cost_available)->map(fn (ForecastSnapshot $s) => [
            'week_start' => $s->week_start->toDateString(),
            'taken_on' => $s->taken_on->toDateString(),
            'forecast_cents' => $s->forecast_cents,
            'bac_cents' => $s->bac_cents,
            'ceiling_cents' => $s->ceiling_cents,
            'actual_cents' => $s->actual_cents,
            'committed_cents' => $s->committed_cents,
            'progress_pct' => $s->cost_progress_pct,
            'margin_pct' => $s->margin_pct,
            'confidence' => $s->confidence,
            'current' => false,
        ])->values()->all();

        if ($cost['available']) {
            $rows[] = [
                'week_start' => $week,
                'taken_on' => $today->toDateString(),
                'forecast_cents' => $cost['forecast_cents'],
                'bac_cents' => $cost['bac_cents'],
                'ceiling_cents' => $cost['ceiling_cents'],
                'actual_cents' => $cost['actual_cents'],
                'committed_cents' => $cost['committed_cents'],
                'progress_pct' => $cost['progress_pct'],
                'margin_pct' => $cost['margin_pct'],
                'confidence' => $cost['confidence'],
                'current' => true,
            ];
        }

        return $rows;
    }

    /** @param  Collection<int, ForecastSnapshot>  $past */
    private function scheduleHistory(Collection $past, array $schedule, string $week, CarbonImmutable $today): array
    {
        $rows = $past->filter(fn (ForecastSnapshot $s) => $s->schedule_available && $s->forecast_finish_on !== null)->map(fn (ForecastSnapshot $s) => [
            'week_start' => $s->week_start->toDateString(),
            'taken_on' => $s->taken_on->toDateString(),
            'forecast_finish_on' => $s->forecast_finish_on->toDateString(),
            'planned_finish_on' => $s->planned_finish_on?->toDateString(),
            'contract_end_on' => $s->contract_end_on?->toDateString(),
            'progress_pct' => $s->schedule_progress_pct,
            'planned_progress_pct' => $s->planned_progress_pct,
            'current' => false,
        ])->values()->all();

        if ($schedule['available'] && $schedule['forecast_finish_on']) {
            $rows[] = [
                'week_start' => $week,
                'taken_on' => $today->toDateString(),
                'forecast_finish_on' => $schedule['forecast_finish_on'],
                'planned_finish_on' => $schedule['planned_finish_on'],
                'contract_end_on' => $schedule['contract_end_on'],
                'progress_pct' => $schedule['progress_pct'],
                'planned_progress_pct' => $schedule['planned_progress_pct'],
                'current' => true,
            ];
        }

        return $rows;
    }
}
