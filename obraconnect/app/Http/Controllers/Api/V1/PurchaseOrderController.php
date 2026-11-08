<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\CostCategory;
use App\Enums\PartnerKind;
use App\Enums\PurchaseStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Compras da obra. Rascunho → aprovada (custo comprometido) → recebida → faturada (custo realizado,
 * pelo valor da fatura); cancelada em qualquer ponto antes de faturada. Gerir: purchases.manage.
 */
class PurchaseOrderController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAny(['purchases.manage', 'costs.view'], $project);
        $status = $request->validate(['status' => ['nullable', Rule::enum(PurchaseStatus::class)]])['status'] ?? null;

        return response()->json(['data' => $project->purchaseOrders()->with(['partner:id,name', 'lines', 'costCenter:id,code,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('number')->get()
            ->map(fn (PurchaseOrder $o) => $this->present($o))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('purchases.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validated($request, $project);

        $order = DB::transaction(function () use ($project, $data, $request): PurchaseOrder {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $order = $project->purchaseOrders()->make(collect($data)->except('lines')->all() + ['cost_center_id' => $project->cost_center_id]);
            $order->number = (int) $project->purchaseOrders()->max('number') + 1;
            $order->created_by_user_id = $request->user()->id;
            $order->save();
            $this->syncLines($order, $data['lines'] ?? []);

            return $order;
        });
        $this->auditor->record('purchase.created', $order, ['project' => $project->code, 'number' => $order->number]);

        return response()->json(['data' => $this->present($order->load(['partner:id,name', 'lines', 'costCenter:id,code,name']))], 201);
    }

    public function update(Request $request, Project $project, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('purchases.manage', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($purchaseOrder, [PurchaseStatus::Draft], 'alterar');
        $data = $this->validated($request, $project, $purchaseOrder);
        DB::transaction(function () use ($purchaseOrder, $data): void {
            $purchaseOrder->fill(collect($data)->except('lines')->all())->save();
            if (array_key_exists('lines', $data)) {
                $this->syncLines($purchaseOrder, $data['lines'] ?? []);
            }
        });

        return response()->json(['data' => $this->present($purchaseOrder->load(['partner:id,name', 'lines', 'costCenter:id,code,name']))]);
    }

    /** approve, receive, invoice, cancel. */
    public function transition(Request $request, Project $project, PurchaseOrder $purchaseOrder, string $action): JsonResponse
    {
        $this->authorize('purchases.manage', $project);
        $this->assertProjectOpen($project);
        $order = $purchaseOrder;

        switch ($action) {
            case 'approve':
                $this->assertStatus($order, [PurchaseStatus::Draft], 'aprovar');
                if (! $order->lines()->exists()) {
                    throw ApiException::unprocessable('A compra não tem linhas.', 'PURCHASE_EMPTY');
                }
                $order->forceFill(['status' => PurchaseStatus::Approved, 'approved_by_user_id' => $request->user()->id, 'approved_at' => now(), 'ordered_on' => $order->ordered_on ?? now('Europe/Lisbon')->toDateString()]);
                break;
            case 'receive':
                $this->assertStatus($order, [PurchaseStatus::Approved], 'dar como recebida');
                $order->lines()->each(fn (PurchaseOrderLine $l) => $l->forceFill(['received_quantity' => $l->quantity])->save());
                $order->forceFill(['status' => PurchaseStatus::Received, 'received_at' => now()]);
                break;
            case 'invoice':
                $this->assertStatus($order, [PurchaseStatus::Approved, PurchaseStatus::Received], 'faturar');
                $data = $request->validate([
                    'invoice_number' => ['required', 'string', 'max:60'],
                    'invoiced_on' => ['required', 'date_format:Y-m-d'],
                    'invoiced_cents' => ['nullable', 'integer', 'min:0'],
                ], [], ['invoice_number' => 'n.º da fatura', 'invoiced_on' => 'data da fatura', 'invoiced_cents' => 'valor da fatura']);
                $order->forceFill([
                    'status' => PurchaseStatus::Invoiced,
                    'invoice_number' => $data['invoice_number'],
                    'invoiced_on' => $data['invoiced_on'],
                    'invoiced_cents' => $data['invoiced_cents'] ?? $order->totalCents(),
                ]);
                break;
            case 'cancel':
                $this->assertStatus($order, [PurchaseStatus::Draft, PurchaseStatus::Approved, PurchaseStatus::Received], 'cancelar');
                $order->forceFill(['status' => PurchaseStatus::Cancelled]);
                break;
            default:
                abort(404);
        }
        $order->save();
        $this->auditor->record("purchase.{$action}", $order, ['project' => $project->code, 'number' => $order->number, 'total_cents' => $order->totalCents()]);

        return response()->json(['data' => $this->present($order->load(['partner:id,name', 'lines', 'costCenter:id,code,name']))]);
    }

    public function destroy(Project $project, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('purchases.manage', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($purchaseOrder, [PurchaseStatus::Draft], 'apagar (cancele-a)');
        $purchaseOrder->delete();

        return response()->noContent();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(PurchaseOrder $order, array $lines): void
    {
        $order->lines()->delete();
        foreach (array_values($lines) as $i => $line) {
            $order->lines()->create([
                'resource_id' => $line['resource_id'] ?? null,
                'budget_item_id' => $line['budget_item_id'] ?? null,
                'description' => $line['description'],
                'unit' => $line['unit'],
                'quantity' => self::decimal($line['quantity']) ?? 0,
                'unit_price_cents' => (int) $line['unit_price_cents'],
                'sort_order' => $i,
            ]);
        }
    }

    /** @param  list<PurchaseStatus>  $allowed */
    private function assertStatus(PurchaseOrder $order, array $allowed, string $doing): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ApiException::conflict("A compra n.º {$order->number} está «{$order->status->label()}»: não é possível {$doing}.", 'PURCHASE_STATUS');
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?PurchaseOrder $order = null): array
    {
        $tenant = $this->tenantContext()->id();

        return $request->validate([
            'partner_id' => [$order ? 'sometimes' : 'required', 'integer', Rule::exists('partners', 'id')->where('tenant_id', $tenant)->where('kind', PartnerKind::Supplier->value)->whereNull('archived_at')],
            'category' => ['sometimes', Rule::enum(CostCategory::class)],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('archived_at')],
            'ordered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expected_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'max:300'],
            'lines.*.resource_id' => ['nullable', 'integer', Rule::exists('resources', 'id')->where('tenant_id', $tenant)],
            'lines.*.budget_item_id' => ['nullable', 'integer', Rule::exists('budget_items', 'id')->whereIn('budget_id', $project->budgets()->pluck('id'))],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.unit' => ['required', 'string', 'max:20'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ], ['partner_id.exists' => 'Escolha um fornecedor ativo.'], ['partner_id' => 'fornecedor', 'category' => 'natureza', 'cost_center_id' => 'centro de custo']);
    }

    /** @return array<string, mixed> */
    private function present(PurchaseOrder $o): array
    {
        $manage = Gate::allows('purchases.manage', $o->project) && ! $o->project->isArchived();

        return [
            'id' => $o->id,
            'number' => $o->number,
            'partner_id' => $o->partner_id,
            'partner' => $o->partner?->name,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'category' => $o->category->value,
            'category_label' => $o->category->label(),
            'cost_center' => $o->costCenter ? ['id' => $o->costCenter->id, 'code' => $o->costCenter->code, 'name' => $o->costCenter->name] : null,
            'cost_center_id' => $o->cost_center_id,
            'ordered_on' => $o->ordered_on?->toDateString(),
            'expected_on' => $o->expected_on?->toDateString(),
            'notes' => $o->notes,
            'total_cents' => $o->totalCents(),
            'invoice_number' => $o->invoice_number,
            'invoiced_on' => $o->invoiced_on?->toDateString(),
            'invoiced_cents' => $o->invoiced_cents,
            'lines' => $o->lines->map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'resource_id' => $l->resource_id,
                'budget_item_id' => $l->budget_item_id,
                'description' => $l->description,
                'unit' => $l->unit,
                'quantity' => (float) $l->quantity,
                'unit_price_cents' => $l->unit_price_cents,
                'received_quantity' => (float) $l->received_quantity,
                'total_cents' => (int) round((float) $l->quantity * $l->unit_price_cents),
            ])->all(),
            'can' => [
                'edit' => $manage && $o->status === PurchaseStatus::Draft,
                'approve' => $manage && $o->status === PurchaseStatus::Draft,
                'receive' => $manage && $o->status === PurchaseStatus::Approved,
                'invoice' => $manage && in_array($o->status, [PurchaseStatus::Approved, PurchaseStatus::Received], true),
                'cancel' => $manage && in_array($o->status, [PurchaseStatus::Draft, PurchaseStatus::Approved, PurchaseStatus::Received], true),
            ],
        ];
    }
}
