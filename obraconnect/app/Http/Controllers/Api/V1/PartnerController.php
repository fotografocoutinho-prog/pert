<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\PartnerKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Rules\ValidPortugueseNif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fornecedores e subempreiteiros da empresa, com especialidades e documentos com validade (alvará,
 * seguros, declarações de não dívida). Fornecedores: purchases.manage; subempreiteiros: subcontractors.manage.
 */
class PartnerController extends Controller
{
    use GuardsProjectWrites;

    /** Dias antes do fim da validade em que um documento passa a "a caducar". */
    public const EXPIRING_DAYS = 30;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(['purchases.manage', 'subcontractors.manage', 'costs.view', 'workforce.manage']);
        $kind = $request->validate(['kind' => ['nullable', Rule::enum(PartnerKind::class)]])['kind'] ?? null;

        return response()->json(['data' => Partner::query()->with(['specialties:id,code,name', 'documents'])
            ->withCount(['contracts', 'purchaseOrders'])
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('name')->get()
            ->map(fn (Partner $p) => $this->present($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeKind($data['kind']);
        $partner = DB::transaction(function () use ($data): Partner {
            $partner = Partner::query()->create(collect($data)->except('specialty_ids')->all());
            $this->syncSpecialties($partner, $data['specialty_ids'] ?? []);

            return $partner;
        });
        $this->auditor->record('partner.created', $partner, ['name' => $partner->name, 'kind' => $partner->kind->value]);

        return response()->json(['data' => $this->present($this->fresh($partner))], 201);
    }

    public function update(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeKind($partner->kind->value);
        $data = $this->validated($request, $partner);
        DB::transaction(function () use ($partner, $data): void {
            if (array_key_exists('archived', $data)) {
                $partner->archived_at = $data['archived'] ? ($partner->archived_at ?? now()) : null;
            }
            $partner->fill(collect($data)->except(['specialty_ids', 'archived', 'kind'])->all())->save();
            if (array_key_exists('specialty_ids', $data)) {
                $this->syncSpecialties($partner, $data['specialty_ids'] ?? []);
            }
        });

        return response()->json(['data' => $this->present($this->fresh($partner))]);
    }

    public function destroy(Partner $partner): Response
    {
        $this->authorizeKind($partner->kind->value);
        if ($partner->contracts()->exists() || $partner->purchaseOrders()->exists() || $partner->workers()->exists()) {
            throw ApiException::conflict('Tem contratos, compras ou trabalhadores. Arquive-o em vez de o apagar.', 'PARTNER_IN_USE');
        }
        $partner->delete();

        return response()->noContent();
    }

    public function storeDocument(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeKind($partner->kind->value);
        $partner->documents()->create($request->validate([
            'title' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['title' => 'documento', 'expires_on' => 'validade']));

        return response()->json(['data' => $this->present($this->fresh($partner))], 201);
    }

    public function destroyDocument(Partner $partner, PartnerDocument $document): JsonResponse
    {
        $this->authorizeKind($partner->kind->value);
        $document->delete();

        return response()->json(['data' => $this->present($this->fresh($partner))]);
    }

    private function authorizeKind(string $kind): void
    {
        $this->authorize($kind === PartnerKind::Subcontractor->value ? 'subcontractors.manage' : 'purchases.manage');
    }

    /** @param  list<int>  $ids */
    private function syncSpecialties(Partner $partner, array $ids): void
    {
        $partner->specialties()->sync(collect($ids)->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $partner->tenant_id]])->all());
    }

    private function fresh(Partner $partner): Partner
    {
        return $partner->load(['specialties:id,code,name', 'documents'])->loadCount(['contracts', 'purchaseOrders']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Partner $partner = null): array
    {
        $tenant = $this->tenantContext()->id();
        $required = $partner ? 'sometimes' : 'required';

        $data = $request->validate([
            'kind' => [$partner ? 'prohibited' : 'required', Rule::enum(PartnerKind::class)],
            'name' => [$required, 'string', 'max:200'],
            'nif' => ['sometimes', 'nullable', 'string', new ValidPortugueseNif, Rule::unique('partners', 'nif')->where('tenant_id', $tenant)->ignore($partner?->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'archived' => $partner ? ['sometimes', 'boolean'] : ['prohibited'],
            'specialty_ids' => ['sometimes', 'array'],
            'specialty_ids.*' => ['integer', Rule::exists('specialties', 'id')->where('tenant_id', $tenant)],
        ], ['nif.unique' => 'Já existe uma entidade com este NIF.'], ['name' => 'nome', 'nif' => 'NIF', 'contact_name' => 'contacto']);
        if (isset($data['nif'])) {
            $data['nif'] = preg_replace('/\D/', '', $data['nif']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Partner $p): array
    {
        $today = now('Europe/Lisbon')->startOfDay();

        return [
            'id' => $p->id,
            'kind' => $p->kind->value,
            'kind_label' => $p->kind->label(),
            'name' => $p->name,
            'nif' => $p->nif,
            'email' => $p->email,
            'phone' => $p->phone,
            'contact_name' => $p->contact_name,
            'address' => $p->address,
            'notes' => $p->notes,
            'archived' => $p->isArchived(),
            'specialties' => $p->specialties->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'documents' => $p->documents->map(fn (PartnerDocument $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'reference' => $d->reference,
                'expires_on' => $d->expires_on?->toDateString(),
                'state' => $d->expires_on === null ? 'valid' : ($d->expires_on->lt($today) ? 'expired' : ($d->expires_on->lte($today->copy()->addDays(self::EXPIRING_DAYS)) ? 'expiring' : 'valid')),
            ])->all(),
            'contracts_count' => (int) ($p->contracts_count ?? 0),
            'purchase_orders_count' => (int) ($p->purchase_orders_count ?? 0),
        ];
    }
}
