<?php

namespace Tests\Feature\Auth;

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rui Costa',
            'email' => 'rui@construcoes.pt',
            'password' => 'Segura2026obra',
            'password_confirmation' => 'Segura2026obra',
            'company_name' => 'Construções Costa',
            'nif' => '',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registration_creates_company_admin_and_trial(): void
    {
        Notification::fake();

        $this->withHeaders($this->spaHeaders)->postJson('/auth/register', $this->payload())->assertCreated();

        $user = User::query()->where('email', 'rui@construcoes.pt')->firstOrFail();
        $tenant = Tenant::query()->where('name', 'Construções Costa')->firstOrFail();

        $this->assertSame($tenant->id, $user->current_tenant_id);
        $this->assertSame(14, $tenant->roles()->count());

        $membership = $user->activeMembershipFor($tenant->id);
        $this->assertSame('admin', app(TenantContext::class)->runAs($tenant, fn () => $membership->role->key));

        $subscription = $tenant->currentSubscription;
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame('professional', $subscription->plan->code);
        $this->assertTrue($subscription->trial_ends_at->isFuture());

        $this->assertTrue(AuditLog::query()->where('event', 'tenant.registered')->where('tenant_id', $tenant->id)->exists());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_invalid_nif_is_rejected(): void
    {
        $this->withHeaders($this->spaHeaders)
            ->postJson('/auth/register', $this->payload(['nif' => '123456780']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nif');
    }

    public function test_company_name_and_terms_are_required(): void
    {
        $this->withHeaders($this->spaHeaders)
            ->postJson('/auth/register', $this->payload(['company_name' => '', 'accept_terms' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_name', 'accept_terms']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->withHeaders($this->spaHeaders)
            ->postJson('/auth/register', $this->payload(['password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_with_invitation_joins_existing_company(): void
    {
        [, $tenant] = $this->createCompany('Empresa Convidante');
        $token = Str::random(64);

        app(TenantContext::class)->runAs($tenant, fn () => Invitation::query()->create([
            'email' => 'encarregado@exemplo.pt',
            'role_id' => $this->roleId($tenant, 'encarregado'),
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(7),
        ]));

        $this->withHeaders($this->spaHeaders)->postJson('/auth/register', $this->payload([
            'email' => 'encarregado@exemplo.pt',
            'company_name' => null,
            'invitation_token' => $token,
        ]))->assertCreated();

        $user = User::query()->where('email', 'encarregado@exemplo.pt')->firstOrFail();

        $this->assertSame(1, Tenant::query()->count(), 'Não deve ser criada uma empresa nova.');
        $this->assertSame($tenant->id, $user->current_tenant_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(Invitation::findByToken($token)->accepted_at);
    }

    public function test_invitation_for_another_email_cannot_be_used_to_register(): void
    {
        [, $tenant] = $this->createCompany();
        $token = Str::random(64);

        app(TenantContext::class)->runAs($tenant, fn () => Invitation::query()->create([
            'email' => 'certo@exemplo.pt',
            'role_id' => $this->roleId($tenant, 'trabalhador'),
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(7),
        ]));

        $this->withHeaders($this->spaHeaders)->postJson('/auth/register', $this->payload([
            'email' => 'errado@exemplo.pt',
            'invitation_token' => $token,
        ]))->assertJsonValidationErrors('invitation_token');
    }

    public function test_disabled_accounts_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'Segura2026obra']);
        $user->forceFill(['is_active' => false])->save();

        $this->withHeaders($this->spaHeaders)
            ->postJson('/auth/login', ['email' => $user->email, 'password' => 'Segura2026obra'])
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_audited(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $owner->forceFill(['password' => 'Segura2026obra'])->save();

        $this->withHeaders($this->spaHeaders)
            ->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Segura2026obra'])
            ->assertOk();

        $this->assertTrue(AuditLog::query()->where('event', 'auth.login')->where('tenant_id', $tenant->id)->exists());
        $this->assertNotNull($owner->refresh()->last_login_at);
    }

    public function test_unverified_users_cannot_use_company_routes(): void
    {
        [$owner] = $this->createCompany();
        $owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($owner)->getJson('/api/v1/company')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email_verified', false);
    }

    public function test_password_reset_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->withHeaders($this->spaHeaders)->postJson('/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->withHeaders($this->spaHeaders)->postJson('/auth/forgot-password', ['email' => 'ninguem@exemplo.pt']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class, function ($notification) use ($user) {
            return str_contains($notification->toMail($user)->actionUrl, '/repor-password/');
        });
    }
}
