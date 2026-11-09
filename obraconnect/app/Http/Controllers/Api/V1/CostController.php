<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Alerts\ForecastWatch;
use App\Audit\Auditor;
use App\Calendar\WorkCalendars;
use App\Economics\CostReport;
use App\Enums\CostCategory;
use App\Enums\InvoiceDraftStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Forecasting\CostForecast;
use App\Forecasting\ScheduleForecast;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\ForecastSnapshot;
use App\Models\InvoiceDraft;
use App\Models\Project;
use App\Models\SupplierDocument;
use Carbon\CarbonImmutable;
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
        if ($source = SupplierDocument::query()->where('cost_entry_id', $costEntry->id)->first()) {
            throw ApiException::conflict("Este custo veio do documento de fornecedor n.º {$source->id} ({$source->label()}): desfaça o lançamento nesse documento.", 'COST_FROM_SUPPLIER_DOC');
        }
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
            fputcsv($out, ['Natureza', 'Previsto', 'Comprometido', 'Realizado', 'Realizado + comprometido'], ';');
            foreach ($report['by_category'] as $row) {
                fputcsv($out, [$row['label'], $m($row['planned_cents']), $m($row['committed_cents']), $m($row['actual_cents']), $m($row['forecast_cents'])], ';');
            }
            fputcsv($out, ['Total', $m($report['planned_cents']), $m($report['committed_cents']), $m($report['actual_cents']), $m($report['forecast_cents'])], ';');
            fputcsv($out, ['Teto', $m($report['ceiling_cents'])], ';');
            fclose($out);
        }, "custos-{$project->code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Painel da empresa: uma linha por obra visível (contrato, autado, custo, previsão e margem).
     * "forecast_cents" continua a ser realizado + comprometido; a previsão do custo no fim (com o que
     * falta fazer) e o fim previsto da obra vêm em "outlook".
     */
    public function finance(AccessResolver $access, WorkCalendars $calendars): JsonResponse
    {
        $this->authorize('finance.view');
        $membership = $this->tenantContext()->membership();
        $visible = $membership ? $access->visibleProjectIds($membership) : [];

        $projects = Project::query()->where('status', '!=', ProjectStatus::Archived->value)
            ->when($visible !== null, fn ($q) => $q->whereKey($visible ?? []))
            ->orderBy('code')->get()
            ->filter(fn (Project $p) => Gate::allows('finance.view', $p));

        // Faturado = valor sem IVA das pré-faturas com a fatura oficial registada.
        $invoiced = InvoiceDraft::query()->with('lines')->where('status', InvoiceDraftStatus::Invoiced->value)
            ->whereIn('project_id', $projects->pluck('id'))->get()
            ->groupBy('project_id')->map(fn ($drafts) => (int) $drafts->sum(fn (InvoiceDraft $d) => $d->totals()['net_cents']));

        $today = CarbonImmutable::today('Europe/Lisbon');
        // A fotografia da semana anterior (ForecastWatch), para mostrar quanto mudou.
        $previous = ForecastSnapshot::query()->whereIn('project_id', $projects->pluck('id'))
            ->where('week_start', '<', ForecastWatch::weekStart($today)->toDateString())
            ->orderByDesc('week_start')->get()->unique('project_id')->keyBy('project_id');
        $rows = $projects->map(function (Project $p) use ($invoiced, $today, $calendars, $previous) {
            $movements = CostReport::movements($p);
            $r = CostReport::for($p, $movements);
            $cost = CostForecast::for($p, $today, $movements, $r);
            $schedule = Gate::any(['planning.view', 'planning.manage'], $p) ? ScheduleForecast::for($p, $today, $calendars->forProject($p)) : null;

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'contract_cents' => $r['revenue']['contract_cents'],
                'certified_cents' => $r['revenue']['certified_cents'],
                'certified_pct' => $r['revenue']['certified_pct'],
                'invoiced_cents' => (int) ($invoiced[$p->id] ?? 0),
                'actual_cents' => $r['actual_cents'],
                'committed_cents' => $r['committed_cents'],
                'forecast_cents' => $r['forecast_cents'],
                'ceiling_cents' => $r['ceiling_cents'],
                'ceiling_used_pct' => $r['ceiling_used_pct'],
                'alert' => $r['alert'],
                'margin_forecast_cents' => $r['margin_forecast_cents'],
                'outlook' => [
                    'cost_available' => $cost['available'],
                    'eac_cents' => $cost['available'] ? $cost['forecast_cents'] : null,
                    'method' => $cost['method'] ?? null,
                    'confidence' => $cost['confidence'] ?? null,
                    'alert' => $cost['alert'] ?? null,
                    'ceiling_used_pct' => $cost['ceiling_used_pct'] ?? null,
                    'margin_cents' => $cost['available'] ? $cost['margin_cents'] : null,
                    'margin_pct' => $cost['margin_pct'] ?? null,
                    'top_reason' => ($cost['reasons'] ?? [])[0]['text'] ?? null,
                    'schedule_available' => $schedule['available'] ?? false,
                    'schedule_status' => $schedule['status'] ?? null,
                    'forecast_finish_on' => $schedule['forecast_finish_on'] ?? null,
                    'contract_end_on' => $schedule['contract_end_on'] ?? $p->planned_end_on?->toDateString(),
                    'delay_contract_days' => $schedule['delay_contract_days'] ?? null,
                    'delay_plan_days' => $schedule['delay_plan_days'] ?? null,
                    'previous' => ($prev = $previous->get($p->id)) ? [
                        'taken_on' => $prev->taken_on->toDateString(),
                        'eac_cents' => $prev->cost_available ? $prev->forecast_cents : null,
                        'forecast_finish_on' => $schedule !== null ? $prev->forecast_finish_on?->toDateString() : null,
                    ] : null,
                ],
            ];
        })->values();

        $sum = fn (string $k) => (int) $rows->sum(fn ($r) => $r[$k] ?? 0);

        return response()->json(['data' => [
            'projects' => $rows,
            'totals' => [
                'contract_cents' => $sum('contract_cents'),
                'certified_cents' => $sum('certified_cents'),
                'invoiced_cents' => $sum('invoiced_cents'),
                'actual_cents' => $sum('actual_cents'),
                'committed_cents' => $sum('committed_cents'),
                'forecast_cents' => $sum('forecast_cents'),
                'margin_forecast_cents' => $sum('margin_forecast_cents'),
                'eac_cents' => (int) $rows->sum(fn ($r) => $r['outlook']['eac_cents'] ?? 0),
                'eac_margin_cents' => (int) $rows->sum(fn ($r) => $r['outlook']['margin_cents'] ?? 0),
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

        $entries = $project->costEntries()->with(['costCenter:id,code,name', 'createdBy:id,name'])->orderByDesc('incurred_on')->get();
        $sources = SupplierDocument::query()->whereIn('cost_entry_id', $entries->pluck('id'))->pluck('id', 'cost_entry_id');

        return $report + [
            'entries' => $entries->map(fn (CostEntry $e) => [
                'id' => $e->id,
                'category' => $e->category->value,
                'category_label' => $e->category->label(),
                'kind' => $e->kind,
                'description' => $e->description,
                'amount_cents' => $e->amount_cents,
                'incurred_on' => $e->incurred_on->toDateString(),
                'cost_center' => $e->costCenter ? "{$e->costCenter->code} · {$e->costCenter->name}" : null,
                'created_by' => $e->createdBy?->name,
                // Lançado a partir de uma fatura de fornecedor: desfaz-se lá, não se apaga aqui.
                'supplier_document_id' => $sources[$e->id] ?? null,
            ])->all(),
            'categories' => CostCategory::options(),
            'can_manage' => Gate::allows('costs.manage', $project) && ! $project->isArchived(),
        ];
    }
}
