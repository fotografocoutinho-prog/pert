<?php

namespace App\Http\Controllers\PublicApi\V1;

use App\Calendar\WorkCalendars;
use App\Economics\CostReport;
use App\Enums\CertificateStatus;
use App\Enums\InvoiceDraftStatus;
use App\Exceptions\ApiException;
use App\Forecasting\CostForecast;
use App\Forecasting\ScheduleForecast;
use App\Http\Controllers\Controller;
use App\Integrations\Integrations;
use App\Integrations\Payloads;
use App\Models\ApiKey;
use App\Models\Certificate;
use App\Models\InvoiceDraft;
use App\Models\Project;
use App\Models\SupplierDocument;
use App\Purchasing\SupplierDocumentIntake;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API pública v1 (chave de API da empresa). Formato estável: campos novos podem aparecer, os
 * existentes não mudam de nome nem de significado. Valores em cêntimos; datas ISO 8601.
 */
class PublicApiController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $key = $this->key($request);

        return response()->json(['data' => [
            'company' => $this->tenantContext()->tenantOrFail()->name,
            'key' => $key->name,
            'scopes' => $key->scopes,
            'version' => config('obraconnect.version'),
        ]]);
    }

    public function projects(Request $request): JsonResponse
    {
        $this->scope($request, 'projects:read');
        $page = Project::query()->with('client:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', (string) $s))
            ->orderBy('id')->paginate(min(max((int) $request->query('per_page', 50), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Project $p) => Payloads::project($p))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function showProject(Request $request, string $project, WorkCalendars $calendars): JsonResponse
    {
        $this->scope($request, 'projects:read');
        $p = $this->findProject($project);
        $today = CarbonImmutable::today('Europe/Lisbon');
        $movements = CostReport::movements($p);
        $report = CostReport::for($p, $movements);
        $cost = CostForecast::for($p, $today, $movements, $report);
        $schedule = ScheduleForecast::for($p, $today, $calendars->forProject($p));

        return response()->json(['data' => Payloads::project($p) + [
            'costs' => [
                'contract_cents' => $report['revenue']['contract_cents'],
                'certified_cents' => $report['revenue']['certified_cents'],
                'actual_cents' => $report['actual_cents'],
                'committed_cents' => $report['committed_cents'],
                'actual_plus_committed_cents' => $report['forecast_cents'],
                'ceiling_cents' => $report['ceiling_cents'],
            ],
            'forecast' => [
                'as_of' => $today->toDateString(),
                'cost' => $cost['available'] ? [
                    'at_completion_cents' => $cost['forecast_cents'],
                    'budget_cents' => $cost['bac_cents'],
                    'method' => $cost['method'],
                    'confidence' => $cost['confidence'],
                    'ceiling_alert' => $cost['alert'],
                    'margin_cents' => $cost['margin_cents'],
                    'margin_pct' => $cost['margin_pct'],
                    'reasons' => array_column($cost['reasons'], 'text'),
                ] : null,
                'schedule' => $schedule['available'] ? [
                    'status' => $schedule['status'],
                    'forecast_finish_on' => $schedule['forecast_finish_on'],
                    'planned_finish_on' => $schedule['planned_finish_on'],
                    'contract_end_on' => $schedule['contract_end_on'],
                    'delay_contract_days' => $schedule['delay_contract_days'],
                    'reasons' => array_column($schedule['reasons'], 'text'),
                ] : null,
            ],
        ]]);
    }

    public function certificates(Request $request, string $project): JsonResponse
    {
        $this->scope($request, 'projects:read');
        $p = $this->findProject($project);
        $certificates = Certificate::query()->where('project_id', $p->id)->where('status', '!=', CertificateStatus::Draft->value)
            ->with(['lines', 'contract.partner:id,name,nif', 'project:id,code,name'])->orderBy('scope')->orderBy('number')->get();

        return response()->json(['data' => $certificates->map(fn (Certificate $c) => Payloads::certificate($c))->values()->all()]);
    }

    /** Pré-faturas (sem valor fiscal), no mesmo formato da exportação JSON, para o programa de faturação. */
    public function invoiceDrafts(Request $request): JsonResponse
    {
        $this->scope($request, 'invoicing:read');
        $data = $request->validate(['status' => ['nullable', Rule::enum(InvoiceDraftStatus::class)]]);
        $drafts = InvoiceDraft::query()->with(['lines', 'project:id,code'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')->limit(500)->get();

        return response()->json(['data' => $drafts->map(fn (InvoiceDraft $d) => Payloads::invoiceDraft($d))->values()->all()]);
    }

    public function storeSupplierDocument(Request $request, SupplierDocumentIntake $intake): JsonResponse
    {
        $this->scope($request, 'supplier-documents:write');
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png,webp', 'max:'.(config('obraconnect.docintel.max_mb') * 1024)],
            'project_code' => ['nullable', 'string', 'max:30'],
        ], [], ['file' => 'ficheiro', 'project_code' => 'código da obra']);
        $project = null;
        if (filled($data['project_code'] ?? null)) {
            $project = Project::query()->where('code', $data['project_code'])->first()
                ?? throw ApiException::unprocessable("Não há nenhuma obra com o código {$data['project_code']}.", 'PROJECT_NOT_FOUND');
            if ($project->isArchived()) {
                throw ApiException::conflict('A obra está arquivada.', 'PROJECT_ARCHIVED');
            }
        }
        $doc = $intake->receive($this->tenantContext()->tenantOrFail(), $request->file('file'), $project, null, 'api:'.$this->key($request)->name);

        return response()->json(['data' => Payloads::supplierDocument($doc->refresh())], 201);
    }

    public function showSupplierDocument(Request $request, string $document): JsonResponse
    {
        $this->scope($request, 'supplier-documents:read');
        $doc = ctype_digit($document) ? SupplierDocument::query()->with('project:id,code')->find((int) $document) : null;

        return $doc ? response()->json(['data' => Payloads::supplierDocument($doc)]) : throw new ApiException('Documento não encontrado.', 'NOT_FOUND', 404);
    }

    // --- interno ---

    private function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    private function scope(Request $request, string $scope): void
    {
        if (! $this->key($request)->allows($scope)) {
            throw new ApiException("Esta chave não tem o âmbito «{$scope}» (".Integrations::SCOPES[$scope].').', 'API_SCOPE_MISSING', 403);
        }
    }

    private function findProject(string $id): Project
    {
        $p = ctype_digit($id) ? Project::query()->with('client:id,name')->find((int) $id) : Project::query()->with('client:id,name')->where('code', $id)->first();

        return $p ?? throw new ApiException('Obra não encontrada.', 'NOT_FOUND', 404);
    }
}
