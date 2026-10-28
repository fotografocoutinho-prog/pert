<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Plan;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_company_users_cannot_access_the_platform_backoffice(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->getJson('/api/v1/platform/tenants')
            ->assertForbidden()->assertJson(['code' => 'PLATFORM_ONLY']);
    }

    public function test_platform_staff_must_have_two_factor_enabled(): void
    {
        $admin = $this->platformUser(withMfa: false);

        $this->actingAs($admin)->getJson('/api/v1/platform/dashboard')
            ->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);
    }

    public function test_dashboard_and_tenant_list(): void
    {
        $this->createCompany('Alfa Construções');
        $this->createCompany('Beta Engenharia');
        $admin = $this->platformUser();

        $this->actingAs($admin)->getJson('/api/v1/platform/dashboard')
            ->assertOk()->assertJsonPath('data.tenants.total', 2);

        $this->actingAs($admin)->getJson('/api/v1/platform/tenants?search=Beta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Engenharia')
            ->assertJsonPath('data.0.plan', 'Professional');
    }

    public function test_platform_admin_does_not_get_access_to_company_data(): void
    {
        $this->createCompany();
        $admin = $this->platformUser();

        $this->actingAs($admin)->getJson('/api/v1/members')
            ->assertForbidden()->assertJson(['code' => 'NO_TENANT']);
    }

    public function test_suspending_a_company_blocks_its_users(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $admin = $this->platformUser();

        $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/suspend", ['reason' => 'Falta de pagamento'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->actingAs($owner)->getJson('/api/v1/company')->assertStatus(423)->assertJson(['code' => 'TENANT_SUSPENDED']);

        $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($owner)->getJson('/api/v1/company')->assertOk();

        $this->assertTrue(AuditLog::query()->where('event', 'platform.tenant_suspended')->where('actor_type', 'platform')->exists());
    }

    public function test_only_super_admin_can_suspend(): void
    {
        [, $tenant] = $this->createCompany();
        $support = $this->platformUser(PlatformRole::Support);

        $this->actingAs($support)->postJson("/api/v1/platform/tenants/{$tenant->id}/suspend", ['reason' => 'Teste'])
            ->assertForbidden()->assertJson(['code' => 'PLATFORM_ROLE']);
    }

    public function test_billing_can_edit_plans_without_code_changes(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        $plan = Plan::query()->where('code', 'start')->firstOrFail();

        $this->actingAs($billing)->putJson("/api/v1/platform/plans/{$plan->id}", [
            'name' => 'Start',
            'description' => 'Novo texto',
            'price_monthly_cents' => 5900,
            'price_yearly_cents' => 59000,
            'currency' => 'EUR',
            'trial_days' => 14,
            'limits' => ['max_users' => 8, 'max_active_projects' => 3, 'storage_mb' => 5120],
            'features' => ['diary'],
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 10,
        ])->assertOk()->assertJsonPath('data.limits.max_users', 8);

        $this->assertSame(5900, $plan->refresh()->price_monthly_cents);
    }

    public function test_support_role_cannot_edit_plans(): void
    {
        $support = $this->platformUser(PlatformRole::Support);
        $plan = Plan::query()->firstOrFail();

        $this->actingAs($support)->putJson("/api/v1/platform/plans/{$plan->id}", [])->assertForbidden();
    }

    public function test_changing_plan_keeps_history_and_applies_new_limits(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $billing = $this->platformUser(PlatformRole::Billing);
        $business = Plan::query()->where('code', 'business')->firstOrFail();

        $this->actingAs($billing)->putJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan_id' => $business->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'current_period_ends_at' => now()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.plan.code', 'business');

        $this->assertSame(2, $tenant->subscriptions()->count());
        $this->actingAs($owner)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.subscription.plan.code', 'business')
            ->assertJsonPath('data.usage.max_users.limit', 100);
    }
}
