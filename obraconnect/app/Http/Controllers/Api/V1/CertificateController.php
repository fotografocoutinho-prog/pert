<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Economics\CertificateBuilder;
use App\Enums\BudgetStatus;
use App\Enums\CertificateStatus;
use App\Enums\ContractStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateLine;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Autos de medição. Ao dono de obra: sai das medições ainda não autadas até ao fim do período, aos
 * preços de venda do orçamento aprovado. De subempreitada: sobre os artigos do contrato, com as
 * quantidades escritas e a retenção do contrato. Rascunho → submetido → aprovado (certificates.approve).
 */
class CertificateController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($project);
        $filter = $request->validate(['contract_id' => ['nullable', 'integer']])['contract_id'] ?? null;

        $certificates = $project->certificates()->with(['contract.partner:id,name'])
            ->when($filter !== null, fn ($q) => $q->where('contract_id', $filter))
            ->orderByDesc('period_end')->orderByDesc('id')->get();

        return response()->json(['data' => $certificates->map(fn (Certificate $c) => $this->summary($c))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('project_id', $project->id)],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['period_start' => 'início do período', 'period_end' => 'fim do período']);
        $contract = isset($data['contract_id']) ? Contract::query()->with('items')->findOrFail($data['contract_id']) : null;
        $this->authorizeAny($contract ? ['subcontractors.manage', 'measurements.manage'] : ['measurements.manage'], $project);
        $this->assertProjectOpen($project);
        if ($contract && $contract->status !== ContractStatus::Active) {
            throw ApiException::conflict('Só se fazem autos de contratos em curso.', 'CONTRACT_STATUS');
        }
        $budget = $contract ? null : $project->budgets()->where('status', BudgetStatus::Approved->value)->latest('approved_at')->first();
        if (! $contract && $budget === null) {
            throw ApiException::conflict('A obra ainda não tem orçamento aprovado.', 'NO_APPROVED_BUDGET');
        }

        $certificate = CertificateBuilder::create($project, $data, $contract, $budget, $request->user()->id);
        $this->auditor->record('certificate.created', $certificate, ['project' => $project->code, 'number' => $certificate->number, 'scope' => $certificate->scope]);

        return response()->json(['data' => $this->present($certificate)], 201);
    }

    public function show(Project $project, Certificate $certificate): JsonResponse
    {
        $this->authorizeView($project);

        return response()->json(['data' => $this->present($certificate)]);
    }

    /** Em rascunho: a quantidade deste período (nos autos de subempreitada é sempre escrita). */
    public function updateLine(Request $request, Project $project, Certificate $certificate, CertificateLine $line): JsonResponse
    {
        $this->authorizeWrite($project, $certificate);
        $this->assertStatus($certificate, [CertificateStatus::Draft], 'alterar');
        $qty = self::decimal($request->validate(['qty_period' => ['required', 'numeric', 'min:-9999999', 'max:9999999']], [], ['qty_period' => 'quantidade'])['qty_period']);
        $line->forceFill(['qty_period' => $qty, 'amount_cents' => (int) round($qty * $line->unit_price_cents)])->save();
        CertificateBuilder::recompute($certificate);

        return response()->json(['data' => $this->present($certificate)]);
    }

    public function submit(Project $project, Certificate $certificate): JsonResponse
    {
        $this->authorizeWrite($project, $certificate);
        $this->assertStatus($certificate, [CertificateStatus::Draft], 'submeter');
        $certificate->forceFill(['status' => CertificateStatus::Submitted, 'submitted_at' => now()])->save();
        $this->auditor->record('certificate.submitted', $certificate, ['project' => $project->code, 'number' => $certificate->number]);

        return response()->json(['data' => $this->present($certificate)]);
    }

    public function approve(Request $request, Project $project, Certificate $certificate): JsonResponse
    {
        $this->authorize('certificates.approve', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($certificate, [CertificateStatus::Submitted], 'aprovar');
        $certificate->forceFill(['status' => CertificateStatus::Approved, 'approved_by_user_id' => $request->user()->id, 'approved_at' => now()])->save();
        $this->auditor->record('certificate.approved', $certificate, ['project' => $project->code, 'number' => $certificate->number, 'amount_cents' => $certificate->amount_cents]);

        return response()->json(['data' => $this->present($certificate)]);
    }

    /** Devolve um auto submetido a rascunho (para corrigir). */
    public function reopen(Project $project, Certificate $certificate): JsonResponse
    {
        $this->authorize('certificates.approve', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($certificate, [CertificateStatus::Submitted], 'devolver');
        $certificate->forceFill(['status' => CertificateStatus::Draft, 'submitted_at' => null])->save();

        return response()->json(['data' => $this->present($certificate)]);
    }

    public function destroy(Project $project, Certificate $certificate): Response
    {
        $this->authorizeWrite($project, $certificate);
        $this->assertStatus($certificate, [CertificateStatus::Draft], 'apagar');
        DB::transaction(function () use ($certificate): void {
            // As medições voltam a ficar livres para o próximo auto.
            $certificate->measurements()->update(['certificate_id' => null]);
            $certificate->delete();
        });

        return response()->noContent();
    }

    private function authorizeView(Project $project): void
    {
        $this->authorizeAny(['measurements.manage', 'certificates.approve', 'budgets.view', 'subcontractors.manage', 'costs.view'], $project);
    }

    private function authorizeWrite(Project $project, Certificate $certificate): void
    {
        $this->authorizeAny($certificate->contract_id ? ['subcontractors.manage', 'measurements.manage'] : ['measurements.manage'], $project);
        $this->assertProjectOpen($project);
    }

    /** @param  list<CertificateStatus>  $allowed */
    private function assertStatus(Certificate $certificate, array $allowed, string $doing): void
    {
        if (! in_array($certificate->status, $allowed, true)) {
            throw ApiException::conflict("O auto n.º {$certificate->number} está «{$certificate->status->label()}»: não é possível {$doing}.", 'CERTIFICATE_STATUS');
        }
    }

    /** @return array<string, mixed> */
    private function summary(Certificate $c): array
    {
        return [
            'id' => $c->id,
            'number' => $c->number,
            'kind' => $c->isOwner() ? 'owner' : 'contract',
            'contract' => $c->contract ? ['id' => $c->contract->id, 'code' => $c->contract->code, 'title' => $c->contract->title, 'partner' => $c->contract->partner?->name] : null,
            'period_start' => $c->period_start->toDateString(),
            'period_end' => $c->period_end->toDateString(),
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'amount_cents' => $c->amount_cents,
            'retention_cents' => $c->retention_cents,
            'net_cents' => $c->amount_cents - $c->retention_cents,
            'approved_at' => $c->approved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Certificate $certificate): array
    {
        $certificate->refresh()->load(['lines', 'contract.partner:id,name', 'approvedBy:id,name', 'createdBy:id,name']);
        $project = $certificate->project;
        $open = ! $project->isArchived();
        $write = $open && Gate::any($certificate->contract_id ? ['subcontractors.manage', 'measurements.manage'] : ['measurements.manage'], $project);
        $approve = $open && Gate::allows('certificates.approve', $project);

        return $this->summary($certificate) + [
            'notes' => $certificate->notes,
            'approved_by' => $certificate->approvedBy?->name,
            'created_by' => $certificate->createdBy?->name,
            'previous_cents' => (int) $certificate->lines->sum(fn (CertificateLine $l) => round((float) $l->qty_previous * $l->unit_price_cents)),
            'lines' => $certificate->lines->map(fn (CertificateLine $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'description' => $l->description,
                'unit' => $l->unit,
                'unit_price_cents' => $l->unit_price_cents,
                'qty_contract' => (float) $l->qty_contract,
                'qty_previous' => (float) $l->qty_previous,
                'qty_period' => (float) $l->qty_period,
                'qty_accumulated' => (float) $l->qty_previous + (float) $l->qty_period,
                'amount_cents' => $l->amount_cents,
                'over_contract' => (float) $l->qty_previous + (float) $l->qty_period > (float) $l->qty_contract + 0.0005,
            ])->all(),
            'can' => [
                'edit' => $write && $certificate->status === CertificateStatus::Draft,
                'submit' => $write && $certificate->status === CertificateStatus::Draft,
                'approve' => $approve && $certificate->status === CertificateStatus::Submitted,
                'reopen' => $approve && $certificate->status === CertificateStatus::Submitted,
                'delete' => $write && $certificate->status === CertificateStatus::Draft,
            ],
        ];
    }
}
