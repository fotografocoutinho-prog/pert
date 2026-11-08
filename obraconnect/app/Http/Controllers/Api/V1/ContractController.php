<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\CertificateStatus;
use App\Enums\ContractStatus;
use App\Enums\PartnerKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Contratos de subempreitada da obra: artigos e preços (podem vir do orçamento), retenção, datas.
 * Rascunho → em curso (conta como custo comprometido) → fechado. Gerir: subcontractors.manage.
 */
class ContractController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorizeAny(['subcontractors.manage', 'costs.view', 'certificates.approve'], $project);

        return response()->json(['data' => $project->contracts()->with(['partner:id,name', 'items', 'certificates'])->orderBy('code')->get()
            ->map(fn (Contract $c) => $this->present($c))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('subcontractors.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validated($request, $project);

        $contract = DB::transaction(function () use ($project, $data, $request): Contract {
            $contract = $project->contracts()->make(collect($data)->except('items')->all());
            $contract->code ??= 'SUB-'.str_pad((string) ($project->contracts()->count() + 1), 2, '0', STR_PAD_LEFT);
            $contract->created_by_user_id = $request->user()->id;
            $contract->save();
            $this->syncItems($contract, $data['items'] ?? []);

            return $contract;
        });
        $this->auditor->record('contract.created', $contract, ['project' => $project->code, 'code' => $contract->code]);

        return response()->json(['data' => $this->present($contract->load(['partner:id,name', 'items', 'certificates']))], 201);
    }

    public function update(Request $request, Project $project, Contract $contract): JsonResponse
    {
        $this->authorize('subcontractors.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validated($request, $project, $contract);
        if ($contract->status !== ContractStatus::Draft && (array_key_exists('items', $data) || array_key_exists('partner_id', $data))) {
            throw ApiException::conflict('O contrato já está em curso: os artigos e o subempreiteiro não mudam.', 'CONTRACT_STATUS');
        }
        DB::transaction(function () use ($contract, $data): void {
            $contract->fill(collect($data)->except('items')->all())->save();
            if (array_key_exists('items', $data)) {
                $this->syncItems($contract, $data['items'] ?? []);
            }
        });

        return response()->json(['data' => $this->present($contract->load(['partner:id,name', 'items', 'certificates']))]);
    }

    /** activate (rascunho → em curso) ou close (em curso → fechado). */
    public function transition(Request $request, Project $project, Contract $contract, string $action): JsonResponse
    {
        $this->authorize('subcontractors.manage', $project);
        $this->assertProjectOpen($project);
        [$from, $to] = match ($action) {
            'activate' => [ContractStatus::Draft, ContractStatus::Active],
            'close' => [ContractStatus::Active, ContractStatus::Closed],
            default => abort(404),
        };
        if ($contract->status !== $from) {
            throw ApiException::conflict("O contrato está «{$contract->status->label()}».", 'CONTRACT_STATUS');
        }
        if ($action === 'activate' && ! $contract->items()->exists()) {
            throw ApiException::unprocessable('O contrato não tem artigos.', 'CONTRACT_EMPTY');
        }
        if ($action === 'close' && $contract->certificates()->whereIn('status', [CertificateStatus::Draft->value, CertificateStatus::Submitted->value])->exists()) {
            throw ApiException::conflict('Há autos deste contrato por aprovar.', 'CONTRACT_HAS_OPEN_CERTIFICATES');
        }
        $contract->forceFill(['status' => $to])->save();
        $this->auditor->record("contract.{$action}", $contract, ['project' => $project->code, 'code' => $contract->code]);

        return response()->json(['data' => $this->present($contract->load(['partner:id,name', 'items', 'certificates']))]);
    }

    public function destroy(Project $project, Contract $contract): Response
    {
        $this->authorize('subcontractors.manage', $project);
        $this->assertProjectOpen($project);
        if ($contract->status !== ContractStatus::Draft || $contract->certificates()->exists()) {
            throw ApiException::conflict('Só se apaga um contrato em rascunho e sem autos.', 'CONTRACT_STATUS');
        }
        $contract->delete();

        return response()->noContent();
    }

    /** @param  list<array<string, mixed>>  $items */
    private function syncItems(Contract $contract, array $items): void
    {
        $contract->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $contract->items()->create([
                'budget_item_id' => $item['budget_item_id'] ?? null,
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => self::decimal($item['quantity']) ?? 0,
                'unit_price_cents' => (int) $item['unit_price_cents'],
                'sort_order' => $i,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Contract $contract = null): array
    {
        $tenant = $this->tenantContext()->id();
        $budgetIds = $project->budgets()->pluck('id');

        return $request->validate([
            'partner_id' => [$contract ? 'sometimes' : 'required', 'integer', Rule::exists('partners', 'id')->where('tenant_id', $tenant)->where('kind', PartnerKind::Subcontractor->value)->whereNull('archived_at')],
            'code' => [$contract ? 'sometimes' : 'nullable', 'string', 'max:30', Rule::unique('contracts', 'code')->where('project_id', $project->id)->ignore($contract?->id)],
            'title' => [$contract ? 'sometimes' : 'required', 'string', 'max:200'],
            'retention_pct' => ['sometimes', 'numeric', 'between:0,30'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.budget_item_id' => ['nullable', 'integer', Rule::exists('budget_items', 'id')->whereIn('budget_id', $budgetIds)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ], ['partner_id.exists' => 'Escolha um subempreiteiro ativo.'], ['partner_id' => 'subempreiteiro', 'title' => 'objeto', 'retention_pct' => 'retenção']);
    }

    /** @return array<string, mixed> */
    private function present(Contract $c): array
    {
        $approved = $c->certificates->where('status', CertificateStatus::Approved);
        $value = $c->valueCents();
        $certified = (int) $approved->sum('amount_cents');
        $manage = Gate::allows('subcontractors.manage', $c->project) && ! $c->project->isArchived();

        return [
            'id' => $c->id,
            'code' => $c->code,
            'title' => $c->title,
            'partner_id' => $c->partner_id,
            'partner' => $c->partner?->name,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'retention_pct' => (float) $c->retention_pct,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'notes' => $c->notes,
            'value_cents' => $value,
            'certified_cents' => $certified,
            'retained_cents' => (int) $approved->sum('retention_cents'),
            'certified_pct' => $value > 0 ? round($certified * 100 / $value, 1) : null,
            'certificates_count' => $c->certificates->count(),
            'items' => $c->items->map(fn (ContractItem $i) => [
                'id' => $i->id,
                'budget_item_id' => $i->budget_item_id,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'unit_price_cents' => $i->unit_price_cents,
                'total_cents' => (int) round((float) $i->quantity * $i->unit_price_cents),
            ])->all(),
            'can' => [
                'edit' => $manage && $c->status === ContractStatus::Draft,
                'activate' => $manage && $c->status === ContractStatus::Draft,
                'close' => $manage && $c->status === ContractStatus::Active,
                'certify' => $manage && $c->status === ContractStatus::Active,
            ],
        ];
    }
}
