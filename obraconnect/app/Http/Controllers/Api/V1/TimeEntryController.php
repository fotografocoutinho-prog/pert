<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\TimeEntryStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Horas por trabalhador, obra e dia. Quem tem time.register_team regista as da equipa; com
 * time.register_own, só as do trabalhador ligado à sua conta. Aprovar: time.approve (o custo entra no
 * mapa de custos só depois de aprovado). Exportar para salários: time.export_payroll.
 */
class TimeEntryController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    /** Semana (segunda a domingo) que contém a data pedida. */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAny(['workforce.view', 'time.register_team', 'time.register_own', 'time.approve', 'costs.view'], $project);
        $date = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']])['week'] ?? now('Europe/Lisbon')->toDateString();
        $monday = CarbonImmutable::parse($date)->startOfWeek();
        $sunday = $monday->addDays(6);

        $entries = $project->timeEntries()->with(['worker:id,name,category,partner_id', 'activity:id,code,name', 'approvedBy:id,name'])
            ->whereDate('work_date', '>=', $monday->toDateString())->whereDate('work_date', '<=', $sunday->toDateString())
            ->when(! $this->canSeeAll($project), fn ($q) => $q->whereIn('worker_id', $this->ownWorkerIds($request)))
            ->orderBy('work_date')->get();

        $seeCost = Gate::any(['workforce.view', 'costs.view'], $project);

        return response()->json(['data' => [
            'week_start' => $monday->toDateString(),
            'week_end' => $sunday->toDateString(),
            'entries' => $entries->map(fn (TimeEntry $e) => $this->present($e, $seeCost))->all(),
            'pending_count' => $project->timeEntries()->where('status', TimeEntryStatus::Pending->value)->count(),
            'can' => [
                'register_team' => Gate::allows('time.register_team', $project) && ! $project->isArchived(),
                'register_own' => Gate::allows('time.register_own', $project) && ! $project->isArchived(),
                'approve' => Gate::allows('time.approve', $project) && ! $project->isArchived(),
            ],
            'own_worker_ids' => $this->ownWorkerIds($request),
        ]]);
    }

    /** Vários registos de uma vez (a folha de um dia): cria ou substitui o do mesmo trabalhador e dia. */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAny(['time.register_team', 'time.register_own'], $project);
        $this->assertProjectOpen($project);
        $today = now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('tenant_id', $this->tenantContext()->id())->where('active', true)],
            'entries.*.hours' => ['required', 'numeric', 'between:0,16'],
            'entries.*.overtime_hours' => ['nullable', 'numeric', 'between:0,12'],
            'entries.*.activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('project_id', $project->id)],
            'entries.*.description' => ['nullable', 'string', 'max:255'],
        ], ['work_date.before_or_equal' => 'Não se registam horas de dias futuros.'], ['work_date' => 'dia', 'entries.*.hours' => 'horas']);

        $team = Gate::allows('time.register_team', $project);
        $own = $this->ownWorkerIds($request);
        $saved = 0;
        DB::transaction(function () use ($project, $data, $team, $own, $request, &$saved): void {
            $workers = Worker::query()->whereKey(array_column($data['entries'], 'worker_id'))->get()->keyBy('id');
            foreach ($data['entries'] as $row) {
                if (! $team && ! in_array((int) $row['worker_id'], $own, true)) {
                    throw ValidationException::withMessages(['entries' => 'Só pode registar as suas próprias horas.']);
                }
                $existing = $project->timeEntries()->where('worker_id', $row['worker_id'])->whereDate('work_date', $data['work_date'])->first();
                if ($existing && $existing->status === TimeEntryStatus::Approved) {
                    throw ValidationException::withMessages(['entries' => "As horas de {$workers[$row['worker_id']]->name} nesse dia já foram aprovadas."]);
                }
                $entry = $existing ?? $project->timeEntries()->make(['worker_id' => $row['worker_id'], 'work_date' => $data['work_date']]);
                $entry->fill([
                    'hours' => self::decimal($row['hours']) ?? 0,
                    'overtime_hours' => self::decimal($row['overtime_hours'] ?? 0) ?? 0,
                    'activity_id' => $row['activity_id'] ?? null,
                    'description' => $row['description'] ?? null,
                ]);
                $entry->hourly_cost_cents = $workers[$row['worker_id']]->hourly_cost_cents;
                $entry->status = TimeEntryStatus::Pending;
                $entry->created_by_user_id ??= $request->user()->id;
                $entry->save();
                $saved++;
            }
        });

        return response()->json(['data' => ['saved' => $saved]], 201);
    }

    public function destroy(Request $request, Project $project, TimeEntry $timeEntry): Response
    {
        $this->authorizeAny(['time.register_team', 'time.register_own'], $project);
        $this->assertProjectOpen($project);
        if ($timeEntry->status === TimeEntryStatus::Approved) {
            throw ApiException::conflict('Horas aprovadas não se apagam.', 'TIME_APPROVED');
        }
        if (! Gate::allows('time.register_team', $project)) {
            abort_unless(in_array($timeEntry->worker_id, $this->ownWorkerIds($request), true), 403);
        }
        $timeEntry->delete();

        return response()->noContent();
    }

    /** approve ou reject, para uma lista de registos. */
    public function decide(Request $request, Project $project, string $decision): JsonResponse
    {
        $this->authorize('time.approve', $project);
        $this->assertProjectOpen($project);
        $status = match ($decision) {
            'approve' => TimeEntryStatus::Approved,
            'reject' => TimeEntryStatus::Rejected,
            default => abort(404),
        };
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']])['ids'];
        $count = $project->timeEntries()->whereKey($ids)->where('status', TimeEntryStatus::Pending->value)
            ->update(['status' => $status->value, 'approved_by_user_id' => $request->user()->id, 'approved_at' => now()]);
        $this->auditor->record("time.{$decision}", $project, ['count' => $count]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    /** CSV de um mês para salários: por trabalhador, dias, horas normais e extra, só aprovadas. */
    public function payroll(Request $request): StreamedResponse
    {
        $this->authorize('time.export_payroll');
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $from = CarbonImmutable::parse("{$month}-01");
        $rows = TimeEntry::query()->with('worker:id,name,nif,category,partner_id', 'project:id,code')
            ->where('status', TimeEntryStatus::Approved->value)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $from->endOfMonth()->toDateString())
            ->get()->groupBy('worker_id');
        $this->auditor->record('time.payroll_exported', null, ['month' => $month, 'workers' => $rows->count()]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Trabalhador', 'NIF', 'Categoria', 'Dias', 'Horas normais', 'Horas extra', 'Obras'], ';');
            $n = fn (float $v) => number_format($v, 2, ',', '');
            foreach ($rows->sortBy(fn ($entries) => $entries->first()->worker->name) as $entries) {
                $w = $entries->first()->worker;
                fputcsv($out, [
                    $w->name, $w->nif, $w->category,
                    $entries->pluck('work_date')->map->toDateString()->unique()->count(),
                    $n((float) $entries->sum(fn ($e) => (float) $e->hours)),
                    $n((float) $entries->sum(fn ($e) => (float) $e->overtime_hours)),
                    $entries->pluck('project.code')->unique()->implode(', '),
                ], ';');
            }
            fclose($out);
        }, "horas-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function canSeeAll(Project $project): bool
    {
        return Gate::any(['workforce.view', 'time.register_team', 'time.approve', 'costs.view'], $project);
    }

    /** @return list<int> */
    private function ownWorkerIds(Request $request): array
    {
        return Worker::query()->where('user_id', $request->user()->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function present(TimeEntry $e, bool $seeCost): array
    {
        return [
            'id' => $e->id,
            'worker_id' => $e->worker_id,
            'worker' => $e->worker?->name,
            'category' => $e->worker?->category,
            'work_date' => $e->work_date->toDateString(),
            'hours' => (float) $e->hours,
            'overtime_hours' => (float) $e->overtime_hours,
            'activity' => $e->activity ? ['id' => $e->activity->id, 'code' => $e->activity->code, 'name' => $e->activity->name] : null,
            'description' => $e->description,
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'cost_cents' => $seeCost ? $e->costCents() : null,
            'approved_by' => $e->approvedBy?->name,
        ];
    }
}
