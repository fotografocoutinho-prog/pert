<?php

namespace App\Actions\Tenancy;

use App\Access\PermissionSynchronizer;
use App\Audit\Auditor;
use App\Enums\SubscriptionStatus;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria uma empresa nova com perfis, o utilizador como administrador e o período experimental.
 */
class RegisterCompany
{
    public function __construct(
        private readonly PermissionSynchronizer $permissions,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{name: string, nif?: string|null, legal_name?: string|null}  $data
     */
    public function handle(User $owner, array $data, ?Plan $plan = null): Tenant
    {
        return DB::transaction(function () use ($owner, $data, $plan): Tenant {
            $tenant = new Tenant([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'nif' => $data['nif'] ?? null,
                'email' => $owner->email,
            ]);
            $tenant->created_by_user_id = $owner->getKey();
            $tenant->settings = ['timezone' => 'Europe/Lisbon', 'require_mfa' => false];
            $tenant->save();

            $roles = $this->permissions->createRolesFor($tenant);

            $this->tenants->runAs($tenant, function () use ($owner, $roles, $tenant, $plan): void {
                Membership::query()->create([
                    'user_id' => $owner->getKey(),
                    'role_id' => $roles[Role::ADMIN]->getKey(),
                    'joined_at' => now(),
                ]);

                $this->startTrial($tenant, $plan);

                $this->auditor->record('tenant.registered', $tenant, ['name' => $tenant->name], actor: $owner, actorType: 'user');
            });

            $owner->forceFill(['current_tenant_id' => $tenant->getKey()])->save();

            return $tenant;
        });
    }

    private function startTrial(Tenant $tenant, ?Plan $plan): void
    {
        $plan ??= Plan::query()->where('code', config('obraconnect.trial.plan_code'))->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Não existem planos configurados. Corra o seeder de planos.');

        $days = $plan->trial_days ?: config('obraconnect.trial.fallback_days');

        $subscription = new Subscription([
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays($days),
            'current_period_starts_at' => now(),
        ]);
        $subscription->tenant()->associate($tenant);
        $subscription->save();
    }
}
