<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Economics\CostReport;
use App\Enums\CostCategory;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mapa de custos da obra (previsto, comprometido, realizado e teto), custos lançados à mão e painel
 * financeiro da empresa. Consultar: costs.view; lançar e mudar o teto: costs.manage; painel: finance.view.
 */
class CostController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function show(Project $project): JsonResponse
    {
        $this->authorizeAny(['costs.view', 'costs.manage', 'finance.view'], $project);

        return response()->json(['data' => $this->present($project)]);
    }

    public function updateCeiling(Request $request, Project $project): JsonResponse
    {
        $this->authorize('costs.manage', $project);
        $this->assertProjectOpen($project);
        $cents = $request->validate(['cost_ceiling_cents' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000000000']], [], ['cost_ceiling_cents' => 'teto'])['cost_ceiling_cents'];
        $project->forceFill(['cost_ceiling_cents' => $cents])->save();
        $this->auditor->record('costs.ceiling_updated', $project, ['code' => $project->code, 'ceiling_cents' => $cents]);

        return response()->json(['data' => $this->present($project)]);
    }

    public function storeEntry(Request $request, Project $project): JsonResponse
    {
        $this->authorize('costs.manage', $project);
        $this->assertProjectOpen($project);
        $data = $request->validate([
            'category' => ['required', Rule::enum(CostCategory::class)],
            'kind' => ['required', Rule::in(['committed', 'actual'])],
            'description' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'not_in:0', 'between:-100000000000,100000000000'],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('tenant_id', $this->tenantContext()->id())],
        ], [], ['category' => 'natureza', 'kind' => 'tipo', 'description' => 'descrição', 'amount_cents' => 'valor', 'incurred_on' => 'data']);
        $entry = $project->costEntries()->make($data);
        $entry->created_by_user_id = $request->user()->id;
        $entry->save();
        $this->auditor->record('costs.entry_created', $entry, ['project' => $project->code, 'amount_cents' => $entry->amount_cents]);

        return response()->json(['data' => $this->present($project)], 201);
    }

    public function destroyEntry(Project $project, CostEntry $costEntry): JsonResponse
    {
        $this->authorize('costs.manage', $project);
        $this->assertProjectOpen($project);
        $costEntry->delete();
        $this->auditor->record('costs.entry_deleted', $project, ['description' => $costEntry->description, 'amount_cents' => $costEntry->amount_cents]);

        return response()->json(['data' => $this->present($project)]);
    }

    public function export(Project $project): StreamedResponse
    {
        $this->authorizeAny(['reports.export', 'costs.view'], $project);
        $report = CostReport::for($project);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $m = fn (?int $c) => $c === null ? '' : number_format($c / 100, 2, ',', '');
            fputcsv($out, ['Natureza', 'Previsto', 'Comprometido', 'Realizado', 'Previsão final'], ';');
            foreach ($report['by_category'] as $row) {
                fputcsv($out, [$row['label'], $m($row['planned_cents']), $m($row['committed_cents']), $m($row['actual_cents']), $m($row['forecast_cents'])], ';');
            }
            fputcsv($out, ['Total', $m($report['planned_cents']), $m($report['committed_cents']), $m($report['actual_cents']), $m($report['forecast_cents'])], ';');
            fputcsv($out, ['Teto', $m($report['ceiling_cents'])], ';');
            fclose($out);
        }, "custos-{$project->code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Painel da empresa: uma linha por obra visível (contrato, autado, custo, previsão e margem). */
    public function finance(AccessResolver $access): JsonResponse
    {
        $this->authorize('finance.view');
        $membership = $this->tenantContext()->membership();
        $visible = $membership ? $access->visibleProjectIds($membership) : [];

        $projects = Project::query()->where('status', '!=', ProjectStatus::Archived->value)
            ->when($visible !== null, fn ($q) => $q->whereKey($visible ?? []))
            ->orderBy('code')->get()
            ->filter(fn (Project $p) => Gate::allows('finance.view', $p));

        $rows = $projects->map(function (Project $p) {
            $r = CostReport::for($p);

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'contract_cents' => $r['revenue']['contract_cents'],
                'certified_cents' => $r['revenue']['certified_cents'],
                'certified_pct' => $r['revenue']['certified_pct'],
                'actual_cents' => $r['actual_cents'],
                'committed_cents' => $r['committed_cents'],
                'forecast_cents' => $r['forecast_cents'],
                'ceiling_cents' => $r['ceiling_cents'],
                'ceiling_used_pct' => $r['ceiling_used_pct'],
                'alert' => $r['alert'],
                'margin_forecast_cents' => $r['margin_forecast_cents'],
            ];
        })->values();

        $sum = fn (string $k) => (int) $rows->sum(fn ($r) => $r[$k] ?? 0);

        return response()->json(['data' => [
            'projects' => $rows,
            'totals' => [
                'contract_cents' => $sum('contract_cents'),
                'certified_cents' => $sum('certified_cents'),
                'actual_cents' => $sum('actual_cents'),
                'committed_cents' => $sum('committed_cents'),
                'forecast_cents' => $sum('forecast_cents'),
                'margin_forecast_cents' => $sum('margin_forecast_cents'),
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(Project $project): array
    {
        $report = CostReport::for($project);
        $centers = CostCenter::query()->whereKey(array_keys($report['by_cost_center']))->get()->keyBy('id');
        $report['by_cost_center'] = collect($report['by_cost_center'])->map(fn ($v, $id) => $v + [
            'cost_center_id' => $id ?: null,
            'label' => isset($centers[$id]) ? "{$centers[$id]->code} · {$centers[$id]->name}" : 'Sem centro de custo',
        ])->values()->all();

        return $report + [
            'entries' => $project->costEntries()->with(['costCenter:id,code,name', 'createdBy:id,name'])->orderByDesc('incurred_on')->get()->map(fn (CostEntry $e) => [
                'id' => $e->id,
                'category' => $e->category->value,
                'category_label' => $e->category->label(),
                'kind' => $e->kind,
                'description' => $e->description,
                'amount_cents' => $e->amount_cents,
                'incurred_on' => $e->incurred_on->toDateString(),
                'cost_center' => $e->costCenter ? "{$e->costCenter->code} · {$e->costCenter->name}" : null,
                'created_by' => $e->createdBy?->name,
            ])->all(),
            'categories' => CostCategory::options(),
            'can_manage' => Gate::allows('costs.manage', $project) && ! $project->isArchived(),
        ];
    }
}
