<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Models\Membership;
use App\Models\SupportAccessGrant;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Estado da sessão para a SPA: utilizador, empresas, empresa ativa e permissões.
     * Funciona sem empresa (ex.: equipa da plataforma ou utilizador sem associações).
     */
    public function show(Request $request, TenantResolver $resolver, AccessResolver $access, PlanLimits $limits): JsonResponse
    {
        $user = $request->user();

        $memberships = $user->allMemberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('tenant')
            ->with(['tenant', 'role' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->get();

        $current = $resolver->resolveMembership($user);
        $currentPayload = null;

        if ($current) {
            $tenant = $current->tenant;
            [$subscription, $permissions] = $this->tenantContext()->runAs($tenant, fn () => [
                $limits->subscription($tenant),
                $access->permissionsFor($current->loadMissing('role')),
            ]);

            $currentPayload = [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'status' => $tenant->status->value,
                    'status_label' => $tenant->status->label(),
                    'requires_mfa' => $tenant->requiresMfa(),
                    'logo_url' => $tenant->logo_path ? route('api.v1.company.logo', ['v' => substr(md5($tenant->logo_path), 0, 8)]) : null,
                ],
                'membership_id' => $current->id,
                'role' => ['id' => $current->role_id, 'key' => $current->role?->key, 'name' => $current->role?->name],
                'permissions' => $permissions,
                'subscription' => $subscription ? [
                    'status' => $subscription->status->value,
                    'status_label' => $subscription->status->label(),
                    'plan' => $subscription->plan->name,
                    'plan_code' => $subscription->plan->code,
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'features' => $subscription->plan->features ?? [],
                ] : null,
            ];
        }

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_pending_confirmation' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
                'platform_role' => $user->platform_role?->value,
                'platform_role_label' => $user->platform_role?->label(),
                'locale' => $user->locale,
            ],
            'tenants' => $memberships->map(fn (Membership $m) => [
                'id' => $m->tenant_id,
                'name' => $m->tenant->name,
                'status' => $m->tenant->status->value,
                'role' => $m->role?->name,
                'is_current' => $current?->tenant_id === $m->tenant_id,
            ])->values(),
            'current' => $currentPayload,
            'support_session' => $this->supportSession($request),
        ]]);
    }

    public function switchTenant(Request $request, int $tenantId, Auditor $auditor): JsonResponse
    {
        $user = $request->user();
        $membership = $user->activeMembershipFor($tenantId);

        if ($membership === null || $membership->tenant()->first() === null) {
            throw ApiException::forbidden('Não pertence a esta empresa.', 'NOT_A_MEMBER');
        }

        if ($membership->tenant->status === TenantStatus::Suspended) {
            throw new ApiException('A empresa está suspensa. Contacte o suporte.', 'TENANT_SUSPENDED', 423);
        }

        $user->forceFill(['current_tenant_id' => $tenantId])->save();

        $auditor->record('auth.tenant_switched', $membership, [], tenantId: $tenantId, actor: $user, actorType: 'user');

        return response()->json(['data' => ['current_tenant_id' => $tenantId]]);
    }

    private function supportSession(Request $request): ?array
    {
        $grantId = $request->hasSession() ? $request->session()->get(ResolveTenant::SUPPORT_SESSION_KEY) : null;

        if ($grantId === null || ! $request->user()->isPlatformStaff()) {
            return null;
        }

        $grant = SupportAccessGrant::withoutGlobalScope(TenantScope::class)->with('tenant')->find($grantId);

        if ($grant === null || ! $grant->isActive()) {
            return null;
        }

        return [
            'grant_id' => $grant->id,
            'tenant' => ['id' => $grant->tenant_id, 'name' => $grant->tenant?->name],
            'expires_at' => $grant->expires_at->toIso8601String(),
            'access_level' => $grant->access_level,
        ];
    }
}
