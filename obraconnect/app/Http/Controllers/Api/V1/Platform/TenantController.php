<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestão comercial das empresas. Mostra metadados (identificação, estado, plano,
 * contagens), nunca o conteúdo operacional da empresa.
 */
class TenantController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        $tenants = Tenant::query()
            ->with('currentSubscription.plan')
            ->withCount(['activeMemberships as members_count'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('nif', 'like', addcslashes($search, '%_\\').'%')))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => $tenants->getCollection()->map(fn (Tenant $t) => $this->summary($t)),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'total' => $tenants->total(),
            ],
        ]);
    }

    public function show(Tenant $tenant, PlanLimits $limits): JsonResponse
    {
        $tenant->loadCount(['activeMemberships as members_count'])->load('currentSubscription.plan');

        // Contacto comercial: administradores da empresa (nome e email apenas).
        $admins = $tenant->activeMemberships()
            ->whereHas('role', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('key', Role::ADMIN))
            ->with('user:id,name,email')
            ->get()
            ->map(fn ($m) => ['name' => $m->user->name, 'email' => $m->user->email]);

        $platformEvents = AuditLog::query()
            ->forTenant($tenant->id)
            ->whereIn('actor_type', ['platform', 'support'])
            ->with('actor:id,name,email')
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json(['data' => $this->summary($tenant) + [
            'admins' => $admins,
            'usage' => $this->tenantContext()->runAs($tenant, fn () => $limits->summary($tenant)),
            'subscriptions' => SubscriptionResource::collection($tenant->subscriptions()->with('plan')->latest('id')->get()),
            'platform_events' => AuditLogResource::collection($platformEvents),
        ]]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        if ($tenant->status === TenantStatus::Suspended) {
            throw ApiException::conflict('A empresa já está suspensa.', 'ALREADY_SUSPENDED');
        }

        $tenant->changeStatus(TenantStatus::Suspended, 'admin: '.$data['reason']);
        $this->auditor->record('platform.tenant_suspended', $tenant, ['reason' => $data['reason']], tenantId: $tenant->id);

        return response()->json(['data' => $this->summary($tenant)]);
    }

    public function reactivate(Tenant $tenant, PlanLimits $limits): JsonResponse
    {
        if ($tenant->status === TenantStatus::Active) {
            throw ApiException::conflict('A empresa já está ativa.', 'ALREADY_ACTIVE');
        }

        // Sem subscrição válida, a empresa volta em só leitura (pode exportar dados).
        $status = $limits->hasValidSubscription($tenant) ? TenantStatus::Active : TenantStatus::ReadOnly;
        $tenant->changeStatus($status, $status === TenantStatus::ReadOnly ? 'subscription_expired' : null);

        $this->auditor->record('platform.tenant_reactivated', $tenant, ['status' => $status->value], tenantId: $tenant->id);

        return response()->json(['data' => $this->summary($tenant)]);
    }

    /**
     * Altera plano, estado e datas. Cria um novo registo quando o plano muda,
     * para manter o histórico.
     */
    public function updateSubscription(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
            'custom_limits' => ['nullable', 'array'],
            'custom_limits.*' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $subscription = DB::transaction(function () use ($tenant, $data): Subscription {
            $current = $tenant->subscriptions()->latest('id')->lockForUpdate()->first();

            if ($current === null || (int) $current->plan_id !== (int) $data['plan_id']) {
                $current?->forceFill(['status' => SubscriptionStatus::Canceled, 'ends_at' => now()])->save();
                $subscription = new Subscription(['current_period_starts_at' => now()]);
                $subscription->tenant()->associate($tenant);
            } else {
                $subscription = $current;
            }

            $subscription->fill($data)->save();

            $this->auditor->record('platform.subscription_updated', $subscription, $data, tenantId: $tenant->id);

            return $subscription;
        });

        // Subscrição válida reativa empresas bloqueadas por expiração (não as suspensas pelo admin).
        if ($subscription->status->grantsAccess()
            && $tenant->status !== TenantStatus::Active
            && $tenant->status_reason === 'subscription_expired') {
            $tenant->changeStatus(TenantStatus::Active);
        }

        return response()->json(['data' => new SubscriptionResource($subscription->load('plan'))]);
    }

    private function summary(Tenant $tenant): array
    {
        $subscription = $tenant->currentSubscription;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'nif' => $tenant->nif,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'status' => $tenant->status->value,
            'status_label' => $tenant->status->label(),
            'status_reason' => $tenant->status_reason,
            'members_count' => $tenant->members_count ?? null,
            'storage_used_bytes' => $tenant->storage_used_bytes,
            'plan' => $subscription?->plan?->name,
            'plan_id' => $subscription?->plan_id,
            'subscription_status' => $subscription?->status->value,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
