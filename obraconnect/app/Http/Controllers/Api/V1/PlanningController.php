<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Calendar\WorkCalendars;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityDependency;
use App\Models\Inspection;
use App\Models\Project;
use App\Planning\Schedule;
use App\Planning\ScheduleCycleException;
use App\Structure\Place;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Planeamento da obra: atividades (com resumos), precedências FS/SS/FF/SF com folga, avanço, e o
 * calendário calculado pelo CPM sobre os dias úteis da obra. Uma atividade com uma inspeção de
 * ponto de paragem por libertar não se dá como concluída. Consultar: planning.view; gerir: planning.manage.
 */
class PlanningController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly WorkCalendars $calendars,
    ) {}

    public function show(Project $project): JsonResponse
    {
        $this->authorizeAny(['planning.view', 'planning.manage'], $project);

        return response()->json(['data' => $this->present($project)]);
    }

    public function storeActivity(Request $request, Project $project): JsonResponse
    {
        $this->authorize('planning.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validated($request, $project);
        $activity = $project->activities()->make($data);
        $activity->code = $data['code'] ?? 'A'.str_pad((string) ((int) $project->activities()->count() + 1), 3, '0', STR_PAD_LEFT);
        $activity->sort_order = (int) $project->activities()->max('sort_order') + 1;
        $activity->save();

        return response()->json(['data' => $this->present($project)], 201);
    }

    public function updateActivity(Request $request, Project $project, Activity $activity): JsonResponse
    {
        $this->authorize('planning.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validated($request, $project, $activity);
        if (($data['parent_id'] ?? null) !== null && $this->isDescendant($project, (int) $data['parent_id'], $activity->id)) {
            throw ValidationException::withMessages(['parent_id' => 'Uma atividade não fica dentro de si própria.']);
        }
        $activity->fill($data)->save();

        return response()->json(['data' => $this->present($project)]);
    }

    public function destroyActivity(Project $project, Activity $activity): JsonResponse
    {
        $this->authorize('planning.manage', $project);
        $this->assertProjectOpen($project);
        if ($activity->inspections()->exists() || $project->timeEntries()->where('activity_id', $activity->id)->exists()) {
            throw ApiException::conflict('Há inspeções ou horas ligadas a esta atividade.', 'ACTIVITY_IN_USE');
        }
        DB::transaction(function () use ($activity): void {
            // As filhas sobem para o nível de cima.
            Activity::query()->where('parent_id', $activity->id)->update(['parent_id' => $activity->parent_id]);
            $activity->delete();
        });

        return response()->json(['data' => $this->present($project)]);
    }

    /**
     * Avanço (0-100). A 100 % a atividade fica concluída — a não ser que tenha uma inspeção com ponto
     * de paragem por libertar.
     */
    public function progress(Request $request, Project $project, Activity $activity): JsonResponse
    {
        $this->authorizeAny(['planning.manage'], $project);
        $this->assertProjectOpen($project);
        $pct = (int) $request->validate(['progress_pct' => ['required', 'integer', 'between:0,100']], [], ['progress_pct' => 'avanço'])['progress_pct'];
        if (Activity::query()->where('parent_id', $activity->id)->exists()) {
            throw ApiException::unprocessable('O avanço de uma atividade resumo vem das suas atividades.', 'ACTIVITY_SUMMARY');
        }
        if ($pct === 100) {
            $hold = $activity->inspections()->holdingWork()->orderBy('number')->first();
            if ($hold instanceof Inspection) {
                throw ApiException::conflict("A inspeção n.º {$hold->number} é um ponto de paragem por libertar: a atividade não pode ser dada como concluída.", 'HOLD_POINT_PENDING');
            }
        }
        $activity->forceFill(['progress_pct' => $pct, 'completed_at' => $pct === 100 ? ($activity->completed_at ?? now()) : null])->save();
        $this->auditor->record('planning.progress', $activity, ['project' => $project->code, 'code' => $activity->code, 'progress_pct' => $pct]);

        return response()->json(['data' => $this->present($project)]);
    }

    public function storeDependency(Request $request, Project $project): JsonResponse
    {
        $this->authorize('planning.manage', $project);
        $this->assertProjectOpen($project);
        $ids = $project->activities()->pluck('id');
        $data = $request->validate([
            'predecessor_id' => ['required', 'integer', Rule::in($ids)],
            'successor_id' => ['required', 'integer', Rule::in($ids), 'different:predecessor_id'],
            'type' => ['sometimes', Rule::in(['FS', 'SS', 'FF', 'SF'])],
            'lag_days' => ['sometimes', 'integer', 'between:-365,365'],
        ], [], ['predecessor_id' => 'atividade anterior', 'successor_id' => 'atividade seguinte', 'lag_days' => 'folga']);

        $summaries = $project->activities()->whereNotNull('parent_id')->pluck('parent_id')->unique();
        if ($summaries->contains($data['predecessor_id']) || $summaries->contains($data['successor_id'])) {
            throw ValidationException::withMessages(['successor_id' => 'As precedências são entre atividades, não entre resumos.']);
        }
        $existing = ActivityDependency::query()->whereIn('successor_id', $ids)->get();
        if ($existing->contains(fn ($d) => $d->predecessor_id === (int) $data['predecessor_id'] && $d->successor_id === (int) $data['successor_id'])) {
            throw ValidationException::withMessages(['successor_id' => 'Essa precedência já existe.']);
        }
        if (Schedule::wouldCycle((int) $data['predecessor_id'], (int) $data['successor_id'], $existing)) {
            throw ValidationException::withMessages(['successor_id' => 'Isso faria um ciclo: a atividade seguinte já vem antes da anterior.']);
        }
        ActivityDependency::query()->create($data + ['type' => 'FS', 'lag_days' => 0]);

        return response()->json(['data' => $this->present($project)], 201);
    }

    public function destroyDependency(Project $project, ActivityDependency $dependency): JsonResponse
    {
        $this->authorize('planning.manage', $project);
        $this->assertProjectOpen($project);
        abort_unless($project->activities()->whereKey($dependency->successor_id)->exists(), 404);
        $dependency->delete();

        return response()->json(['data' => $this->present($project)]);
    }

    private function isDescendant(Project $project, int $candidate, int $ancestor): bool
    {
        $parents = $project->activities()->pluck('parent_id', 'id');
        for ($id = $candidate, $guard = 0; $id !== null && $guard < 100; $id = $parents[$id] ?? null, $guard++) {
            if ($id === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Activity $activity = null): array
    {
        $days = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:3650'];
        $data = $request->validate([
            'name' => [$activity ? 'sometimes' : 'required', 'string', 'max:200'],
            'code' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('activities', 'code')->where('project_id', $project->id)->ignore($activity?->id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('activities', 'id')->where('project_id', $project->id), Rule::notIn([$activity?->id])],
            'duration_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'optimistic_days' => $days, 'likely_days' => $days, 'pessimistic_days' => $days,
            'start_no_earlier_than' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ] + Place::rules($project), [], ['name' => 'nome', 'duration_days' => 'duração', 'parent_id' => 'dentro de']);
        if (array_key_exists('code', $data) && blank($data['code'])) {
            unset($data['code']);
        }
        $o = $data['optimistic_days'] ?? $activity?->optimistic_days;
        $m = $data['likely_days'] ?? $activity?->likely_days;
        $p = $data['pessimistic_days'] ?? $activity?->pessimistic_days;
        if ($o !== null && $m !== null && $p !== null && ! ((float) $o <= (float) $m && (float) $m <= (float) $p)) {
            throw ValidationException::withMessages(['likely_days' => 'PERT: otimista ≤ mais provável ≤ pessimista.']);
        }
        if (array_key_exists('location_id', $data) || array_key_exists('element_id', $data)) {
            $data = array_merge($data, Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Project $project): array
    {
        $activities = $project->activities()->with(['predecessors', 'inspections' => fn ($q) => $q->select(['id', 'number', 'title', 'status', 'activity_id', 'hold_point', 'hold_released_at'])->withCount('reinspections')])
            ->orderBy('sort_order')->orderBy('id')->get();
        $start = CarbonImmutable::parse(($project->starts_on ?? $project->created_at ?? now())->toDateString());

        try {
            $schedule = Schedule::compute($activities, $start, $this->calendars->forProject($project));
            $error = null;
        } catch (ScheduleCycleException $e) {
            $schedule = ['activities' => [], 'start_on' => null, 'finish_on' => null, 'duration_days' => 0, 'critical' => [], 'pert' => null];
            $error = $e->getMessage();
        }

        $plannedEnd = $project->planned_end_on?->toDateString();

        return [
            'project' => ['starts_on' => $start->toDateString(), 'planned_end_on' => $plannedEnd],
            'start_on' => $schedule['start_on'],
            'finish_on' => $schedule['finish_on'],
            'duration_days' => $schedule['duration_days'],
            'late' => $plannedEnd !== null && $schedule['finish_on'] !== null && $schedule['finish_on'] > $plannedEnd,
            'pert' => $schedule['pert'],
            'error' => $error,
            // As contas do CPM primeiro (num resumo, a duração e o avanço são os das filhas).
            'activities' => $activities->map(fn (Activity $a) => ($schedule['activities'][$a->id] ?? []) + [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->name,
                'duration_days' => $a->duration_days,
                'optimistic_days' => $a->optimistic_days === null ? null : (float) $a->optimistic_days,
                'likely_days' => $a->likely_days === null ? null : (float) $a->likely_days,
                'pessimistic_days' => $a->pessimistic_days === null ? null : (float) $a->pessimistic_days,
                'start_no_earlier_than' => $a->start_no_earlier_than?->toDateString(),
                'location_id' => $a->location_id,
                'element_id' => $a->element_id,
                'completed_at' => $a->completed_at?->toIso8601String(),
                'holds' => $a->inspections->filter(fn (Inspection $i) => $i->isHoldingWork())->map(fn (Inspection $i) => ['id' => $i->id, 'number' => $i->number])->values()->all(),
                'predecessors' => $a->predecessors->map(fn (ActivityDependency $d) => ['id' => $d->id, 'predecessor_id' => $d->predecessor_id, 'type' => $d->type, 'lag_days' => $d->lag_days])->all(),
                'progress_pct' => $a->progress_pct,
            ])->values()->all(),
            'can_manage' => Gate::allows('planning.manage', $project) && ! $project->isArchived(),
        ];
    }
}
