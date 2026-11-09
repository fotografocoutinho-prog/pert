<?php

namespace App\Http\Controllers\Api\V1;

use App\Calendar\WorkCalendars;
use App\Forecasting\CostForecast;
use App\Forecasting\ScheduleForecast;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Previsões explicadas da obra: custo no fim (quem vê custos: costs.view, costs.manage ou
 * finance.view) e fim da obra (quem vê o planeamento: planning.view ou planning.manage). Cada parte
 * só aparece a quem tem acesso a ela; sem nenhuma das duas, 403.
 */
class ForecastController extends Controller
{
    public function __construct(private readonly WorkCalendars $calendars) {}

    public function show(Project $project): JsonResponse
    {
        $cost = Gate::any(['costs.view', 'costs.manage', 'finance.view'], $project);
        $schedule = Gate::any(['planning.view', 'planning.manage'], $project);
        abort_unless($cost || $schedule, 403);
        $today = CarbonImmutable::today('Europe/Lisbon');

        return response()->json(['data' => [
            'as_of' => $today->toDateString(),
            'cost' => $cost ? CostForecast::for($project, $today) : null,
            'schedule' => $schedule ? ScheduleForecast::for($project, $today, $this->calendars->forProject($project)) : null,
        ]]);
    }
}
