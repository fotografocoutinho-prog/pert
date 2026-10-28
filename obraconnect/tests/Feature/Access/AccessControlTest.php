<?php

namespace Tests\Feature\Access;

use App\Access\PermissionRegistry;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_me_returns_permissions_of_the_active_membership(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $worker = $this->addMember($tenant, 'trabalhador');

        $this->actingAs($owner)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current.role.key', 'admin')
            ->assertJsonPath('data.current.permissions', PermissionRegistry::keys());

        $this->actingAs($worker)->getJson('/api/v1/me')
            ->assertJsonPath('data.current.permissions', ['company.view']);
    }

    public function test_member_without_permission_gets_403(): void
    {
        [, $tenant] = $this->createCompany();
        $worker = $this->addMember($tenant, 'trabalhador');

        $this->actingAs($worker)->getJson('/api/v1/members')->assertForbidden();
        $this->actingAs($worker)->putJson('/api/v1/company', ['name' => 'X', 'country_code' => 'PT'])->assertForbidden();
        $this->actingAs($worker)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_admin_invites_member_and_email_is_queued(): void
    {
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();

        $this->actingAs($owner)->postJson('/api/v1/invitations', [
            'email' => 'Fiscal@Exemplo.pt',
            'role_id' => $this->roleId($tenant, 'fiscal'),
        ])->assertCreated()->assertJsonPath('data.email', 'fiscal@exemplo.pt');

        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('fiscal@exemplo.pt') && strlen($m->token) === 64);

        // O token em claro nunca fica guardado.
        $stored = app(TenantContext::class)->runAs($tenant, fn () => Invitation::query()->firstOrFail());
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m) => $stored->token_hash === Invitation::hashToken($m->token));
    }

    public function test_cannot_invite_with_a_role_more_powerful_than_your_own(): void
    {
        Mail::fake();
        [, $tenant] = $this->createCompany();
        $director = $this->addMember($tenant, 'diretor_obra');

        $this->actingAs($director)->postJson('/api/v1/invitations', [
            'email' => 'x@exemplo.pt',
            'role_id' => $this->roleId($tenant, 'admin'),
        ])->assertForbidden()->assertJson(['code' => 'ROLE_ESCALATION']);

        $this->actingAs($director)->postJson('/api/v1/invitations', [
            'email' => 'y@exemplo.pt',
            'role_id' => $this->roleId($tenant, 'encarregado'),
        ])->assertCreated();
    }

    public function test_plan_user_limit_is_enforced(): void
    {
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        Subscription::query()->where('tenant_id', $tenant->id)->update(['custom_limits' => json_encode(['max_users' => 2])]);

        $role = $this->roleId($tenant, 'trabalhador');

        $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'a@exemplo.pt', 'role_id' => $role])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'b@exemplo.pt', 'role_id' => $role])
            ->assertStatus(402)
            ->assertJson(['code' => 'PLAN_LIMIT_REACHED']);
    }

    public function test_duplicate_invitation_and_existing_member_are_rejected(): void
    {
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        $role = $this->roleId($tenant, 'trabalhador');

        $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'a@exemplo.pt', 'role_id' => $role])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'a@exemplo.pt', 'role_id' => $role])->assertConflict();
        $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => $owner->email, 'role_id' => $role])
            ->assertConflict()->assertJson(['code' => 'ALREADY_MEMBER']);
    }

    public function test_company_cannot_lose_its_last_admin(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $membershipId = $owner->activeMembershipFor($tenant->id)->id;

        $this->actingAs($owner)
            ->patchJson("/api/v1/members/{$membershipId}", ['role_id' => $this->roleId($tenant, 'fiscal')])
            ->assertUnprocessable()
            ->assertJson(['code' => 'LAST_ADMIN']);

        $this->actingAs($owner)->deleteJson("/api/v1/members/{$membershipId}")
            ->assertUnprocessable()->assertJson(['code' => 'LAST_ADMIN']);
    }

    public function test_admin_can_demote_himself_when_another_admin_exists(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->addMember($tenant, 'admin');
        $membershipId = $owner->activeMembershipFor($tenant->id)->id;

        $this->actingAs($owner)
            ->patchJson("/api/v1/members/{$membershipId}", ['role_id' => $this->roleId($tenant, 'diretor_tecnico')])
            ->assertOk()
            ->assertJsonPath('data.role.key', 'diretor_tecnico');
    }

    public function test_lower_privileged_manager_cannot_suspend_an_admin(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $manager = $this->addMember($tenant, 'administrativo');

        // Dá ao perfil administrativo a permissão de gerir membros.
        app(TenantContext::class)->runAs($tenant, function () {
            $role = Role::query()->where('key', 'administrativo')->firstOrFail();
            $role->permissions()->attach(Permission::query()->where('key', 'members.manage')->value('id'));
        });

        $this->actingAs($manager)
            ->patchJson('/api/v1/members/'.$owner->activeMembershipFor($tenant->id)->id, ['status' => 'suspended'])
            ->assertForbidden()
            ->assertJson(['code' => 'ROLE_ESCALATION']);
    }

    public function test_custom_roles_can_be_created_and_edited(): void
    {
        [$owner] = $this->createCompany();

        $id = $this->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Chefe de equipa',
            'permissions' => ['company.view', 'members.view'],
        ])->assertCreated()->assertJsonPath('data.key', 'chefe_de_equipa')->json('data.id');

        $this->actingAs($owner)->putJson("/api/v1/roles/{$id}", [
            'name' => 'Chefe de equipa',
            'permissions' => ['company.view'],
        ])->assertOk()->assertJsonPath('data.permissions', ['company.view']);

        $this->actingAs($owner)->deleteJson("/api/v1/roles/{$id}")->assertNoContent();
    }

    public function test_admin_role_is_locked(): void
    {
        [$owner, $tenant] = $this->createCompany();

        $this->actingAs($owner)
            ->putJson('/api/v1/roles/'.$this->roleId($tenant, 'admin'), ['name' => 'Admin', 'permissions' => []])
            ->assertForbidden()
            ->assertJson(['code' => 'ROLE_LOCKED']);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->addMember($tenant, 'fiscal');

        $this->actingAs($owner)->deleteJson('/api/v1/roles/'.$this->roleId($tenant, 'fiscal'))
            ->assertConflict()->assertJson(['code' => 'ROLE_IN_USE']);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'X', 'permissions' => ['platform.everything']])
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_company_admin_can_require_mfa_for_all_members(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $worker = $this->addMember($tenant, 'trabalhador');

        $this->actingAs($owner)->putJson('/api/v1/company/security', ['require_mfa' => true])
            ->assertUnprocessable()->assertJson(['code' => 'MFA_REQUIRED_FOR_ADMIN']);

        $owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($owner)->putJson('/api/v1/company/security', ['require_mfa' => true])->assertOk();

        $this->actingAs($worker)->getJson('/api/v1/company')->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);
    }
}
