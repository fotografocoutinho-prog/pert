<?php

namespace Tests\Feature\Billing;

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    public function test_expired_trial_puts_company_in_read_only_and_later_suspends_it(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        Subscription::query()->where('tenant_id', $tenant->id)->update(['trial_ends_at' => now()->subDay()]);

        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();

        $this->assertSame(TenantStatus::ReadOnly, $tenant->refresh()->status);
        $this->assertSame('expired', $tenant->currentSubscription->status->value);

        // Só leitura: consulta e exportação sim, alterações não.
        $this->actingAs($owner)->getJson('/api/v1/company')->assertOk();
        $this->actingAs($owner)->putJson('/api/v1/company', ['name' => 'Novo', 'country_code' => 'PT'])
            ->assertStatus(423)->assertJson(['code' => 'TENANT_READ_ONLY']);
        $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated();

        $this->travel(config('obraconnect.lifecycle.read_only_days_before_suspension') + 1)->days();
        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();

        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
    }

    public function test_active_subscription_past_period_becomes_past_due_then_expired(): void
    {
        [, $tenant] = $this->createCompany();
        Subscription::query()->where('tenant_id', $tenant->id)->update([
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_ends_at' => now()->subDay(),
        ]);

        $this->artisan('obraconnect:subscriptions-sweep');
        $this->assertSame('past_due', $tenant->currentSubscription()->first()->status->value);
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status, 'Em atraso mantém acesso durante a carência.');

        $this->travel(config('obraconnect.lifecycle.past_due_grace_days') + 1)->days();
        $this->artisan('obraconnect:subscriptions-sweep');

        $this->assertSame('expired', $tenant->currentSubscription()->first()->status->value);
        $this->assertSame(TenantStatus::ReadOnly, $tenant->refresh()->status);
    }

    public function test_renewing_subscription_reactivates_read_only_company(): void
    {
        [$owner, $tenant] = $this->createCompany();
        Subscription::query()->where('tenant_id', $tenant->id)->update(['trial_ends_at' => now()->subDay()]);
        $this->artisan('obraconnect:subscriptions-sweep');

        $this->actingAs($this->platformUser())->putJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan_id' => Plan::query()->where('code', 'start')->value('id'),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_ends_at' => now()->addMonth()->toDateString(),
        ])->assertOk();

        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->actingAs($owner)->putJson('/api/v1/company', ['name' => 'Renovada', 'country_code' => 'PT'])->assertOk();
    }

    public function test_manually_suspended_company_is_not_reactivated_by_renewal(): void
    {
        [, $tenant] = $this->createCompany();
        $admin = $this->platformUser();
        $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/suspend", ['reason' => 'Fraude']);

        $this->actingAs($admin)->putJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan_id' => Plan::query()->where('code', 'start')->value('id'),
            'status' => 'active',
            'billing_cycle' => 'monthly',
        ])->assertOk();

        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
    }
}
