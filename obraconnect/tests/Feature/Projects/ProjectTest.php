<?php

namespace Tests\Feature\Projects;

use App\Access\PermissionRegistry;
use App\Access\RoleTemplates;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    private function createProject(User $user, array $data = []): int
    {
        return $this->actingAs($user)->postJson('/api/v1/projects', $data + ['name' => 'Moradia T3'])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Permissões de obra de um perfil-modelo, comparadas sem ordem (a ordem vem da base de dados).
     *
     * @return \Closure(array): bool
     */
    private function projectPermissionsOf(string $template): \Closure
    {
        $expected = collect(RoleTemplates::permissionsFor($template))->filter(fn ($k) => PermissionRegistry::isProjectScoped($k))->sort()->values()->all();

        return fn (array $p) => collect($p)->sort()->values()->all() === $expected;
    }

    private function membershipId(Tenant $tenant, User $user): int
    {
        return app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
    }

    public function test_admin_creates_project_with_generated_code_and_is_in_the_team(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->postJson('/api/v1/projects', [
            'name' => 'Moradia T3 em Leiria',
            'type' => 'new_build',
            'contract_value_cents' => 25000000,
            'starts_on' => '2026-11-10',
            'planned_end_on' => '2027-06-30',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'OB-'.now()->format('Y').'-001')
            ->assertJsonPath('data.status', 'planning')
            ->assertJsonPath('data.type_label', 'Construção nova')
            ->assertJsonPath('data.team_count', 1)
            ->assertJsonPath('data.permissions', fn (array $p) => in_array('diary.submit', $p, true));

        $this->createProject($owner);
        $this->actingAs($owner)->getJson('/api/v1/projects')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.code', 'OB-'.now()->format('Y').'-002');
    }

    public function test_end_date_cannot_be_before_start(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'X', 'starts_on' => '2026-11-10', 'planned_end_on' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('planned_end_on');

        $id = $this->createProject($owner, ['starts_on' => '2026-11-10']);
        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}", ['planned_end_on' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('planned_end_on');
    }

    public function test_project_role_without_all_projects_only_sees_its_team_projects(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $director = $this->addMember($tenant, 'diretor_obra');
        $mine = $this->createProject($owner, ['name' => 'Obra do diretor']);
        $other = $this->createProject($owner, ['name' => 'Outra obra']);

        // Antes de entrar na equipa não vê nenhuma.
        $this->actingAs($director)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($director)->getJson("/api/v1/projects/{$mine}")->assertForbidden();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$mine}/members", [
            'membership_id' => $this->membershipId($tenant, $director),
        ])->assertCreated()->assertJsonPath('data.role.key', 'diretor_obra');

        $this->actingAs($director)->getJson('/api/v1/projects')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine);
        $this->actingAs($director)->getJson("/api/v1/projects/{$mine}")->assertOk()
            ->assertJsonPath('data.permissions', $this->projectPermissionsOf('diretor_obra'));
        $this->actingAs($director)->getJson("/api/v1/projects/{$other}")->assertForbidden();
        $this->actingAs($director)->putJson("/api/v1/projects/{$other}", ['name' => 'Tentativa'])->assertForbidden();
        $this->actingAs($director)->putJson("/api/v1/projects/{$mine}", ['name' => 'Novo nome'])->assertOk();
    }

    public function test_role_with_all_projects_sees_every_project(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $measurer = $this->addMember($tenant, 'medidor_orcamentista');
        $id = $this->createProject($owner);
        $this->createProject($owner);

        $this->actingAs($measurer)->getJson('/api/v1/projects')->assertJsonPath('meta.total', 2);
        // Vê, mas o perfil não tem projects.update nem diário.
        $this->actingAs($measurer)->getJson("/api/v1/projects/{$id}")->assertJsonPath('data.permissions', $this->projectPermissionsOf('medidor_orcamentista'));
        $this->actingAs($measurer)->putJson("/api/v1/projects/{$id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($measurer)->postJson('/api/v1/projects', ['name' => 'X'])->assertForbidden();
    }

    public function test_project_role_can_differ_from_company_role(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $worker = $this->addMember($tenant, 'trabalhador');
        $id = $this->createProject($owner);

        // Trabalhador na empresa, encarregado nesta obra: pode escrever no diário só aqui.
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
            'role_id' => $this->roleId($tenant, 'encarregado'),
        ])->assertCreated();

        $this->actingAs($worker)->getJson("/api/v1/projects/{$id}")
            ->assertJsonPath('data.permissions', $this->projectPermissionsOf('encarregado'));
        $this->actingAs($worker)->postJson("/api/v1/projects/{$id}/diary", ['entry_date' => now()->toDateString()])->assertCreated();
    }

    public function test_cannot_add_team_member_with_a_more_powerful_project_role(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $director = $this->addMember($tenant, 'diretor_obra');
        $worker = $this->addMember($tenant, 'trabalhador');
        $id = $this->createProject($owner);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $director),
        ])->assertCreated();

        // O diretor de obra gere a equipa, mas não pode dar o perfil de diretor técnico (acesso a todas as obras).
        $this->actingAs($director)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
            'role_id' => $this->roleId($tenant, 'diretor_tecnico'),
        ])->assertForbidden()->assertJsonPath('code', 'ROLE_ESCALATION');

        // O formulário só oferece quem ainda não está na equipa e perfis que ele pode dar.
        $options = $this->actingAs($director)->getJson("/api/v1/projects/{$id}/members/options")->assertOk()->json('data');
        $this->assertSame([$worker->email], array_column($options['members'], 'email'));
        $roleKeys = array_column($options['roles'], 'key');
        $this->assertContains('encarregado', $roleKeys);
        $this->assertNotContains('diretor_tecnico', $roleKeys);
        $this->assertNotContains('admin', $roleKeys);

        $this->actingAs($director)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
            'role_id' => $this->roleId($tenant, 'encarregado'),
        ])->assertCreated();

        // Nem retirar o administrador da equipa.
        $adminMember = collect($this->actingAs($director)->getJson("/api/v1/projects/{$id}/members")->json('data'))
            ->firstWhere('role.key', 'admin');
        $this->actingAs($director)->deleteJson("/api/v1/projects/{$id}/members/{$adminMember['id']}")
            ->assertForbidden();

        $this->actingAs($director)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
        ])->assertConflict()->assertJsonPath('code', 'ALREADY_IN_TEAM');
    }

    public function test_team_member_must_belong_to_the_company_and_be_active(): void
    {
        [$owner, $tenant] = $this->createCompany('A');
        [$ownerB, $b] = $this->createCompany('B');
        $id = $this->createProject($owner);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($b, $ownerB),
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_id');

        $worker = $this->addMember($tenant, 'trabalhador');
        app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $worker->id)->update(['status' => 'suspended']));

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
        ])->assertUnprocessable()->assertJsonPath('code', 'MEMBERSHIP_INACTIVE');
    }

    public function test_projects_are_isolated_between_companies(): void
    {
        [$ownerA] = $this->createCompany('A');
        [$ownerB, $b] = $this->createCompany('B');
        $id = $this->createProject($ownerA);

        $this->actingAs($ownerB)->getJson("/api/v1/projects/{$id}")->assertNotFound();
        $this->actingAs($ownerB)->getJson("/api/v1/projects/{$id}/diary")->assertNotFound();
        $this->actingAs($ownerB)->getJson('/api/v1/projects')->assertJsonPath('meta.total', 0);

        // O código repete-se entre empresas sem conflito.
        $this->createProject($ownerB);
        $this->assertSame(2, Project::withoutGlobalScopes()->where('code', 'OB-'.now()->format('Y').'-001')->count());
    }

    public function test_database_rejects_team_member_from_another_company(): void
    {
        [$ownerA, $a] = $this->createCompany('A');
        [$ownerB, $b] = $this->createCompany('B');
        $id = $this->createProject($ownerA);

        $this->expectException(QueryException::class);

        // Inserção direta: as FKs compostas recusam a associação de outra empresa.
        DB::table('project_members')->insert([
            'tenant_id' => $a->id,
            'project_id' => $id,
            'membership_id' => $this->membershipId($b, $ownerB),
            'role_id' => $this->roleId($a, 'admin'),
        ]);
    }

    public function test_plan_limit_counts_open_projects_only(): void
    {
        [$owner, $tenant] = $this->createCompany();
        app(TenantContext::class)->runAs($tenant, fn () => Subscription::query()->latest('id')->first()->forceFill(['custom_limits' => ['max_active_projects' => 1]])->save());

        $first = $this->createProject($owner);
        $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Segunda'])
            ->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');

        // Concluída deixa de contar; reabrir volta a esbarrar no limite.
        $this->actingAs($owner)->putJson("/api/v1/projects/{$first}", ['status' => 'completed'])->assertOk();
        $second = $this->createProject($owner, ['name' => 'Segunda']);
        $this->actingAs($owner)->putJson("/api/v1/projects/{$first}", ['status' => 'active'])
            ->assertStatus(402);

        $this->actingAs($owner)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.usage.max_active_projects.used', 1)
            ->assertJsonPath('data.usage.max_active_projects.limit', 1);
        $this->assertNotSame($first, $second);
    }

    public function test_closing_a_project_needs_the_archive_permission(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $director = $this->addMember($tenant, 'diretor_obra');
        $id = $this->createProject($owner);
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", ['membership_id' => $this->membershipId($tenant, $director)]);

        $this->actingAs($director)->putJson("/api/v1/projects/{$id}", ['status' => 'active'])->assertOk();
        $this->actingAs($director)->putJson("/api/v1/projects/{$id}", ['status' => 'archived'])->assertForbidden();

        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}", ['status' => 'archived'])->assertOk()
            ->assertJsonPath('data.status_label', 'Arquivada');

        // Arquivada: não se edita nem se mexe na equipa ou no diário até reabrir.
        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}", ['name' => 'X'])->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", ['entry_date' => now()->toDateString()])->assertConflict();
        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}", ['status' => 'active'])->assertOk();
    }

    public function test_project_changes_are_audited_with_old_and_new_values(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $id = $this->createProject($owner, ['name' => 'Nome antigo']);

        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}", ['name' => 'Nome novo'])->assertOk();

        $log = AuditLog::query()->where('tenant_id', $tenant->id)->where('event', 'project.updated')->firstOrFail();
        $this->assertSame(['name' => 'Nome antigo'], $log->properties['old']);
        $this->assertSame(['name' => 'Nome novo'], $log->properties['new']);
    }

    public function test_role_used_in_a_project_team_cannot_be_deleted(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $worker = $this->addMember($tenant, 'trabalhador');
        $id = $this->createProject($owner);
        $roleId = $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'Apontador', 'permissions' => ['projects.view']])->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/members", [
            'membership_id' => $this->membershipId($tenant, $worker),
            'role_id' => $roleId,
        ])->assertCreated();

        $this->actingAs($owner)->deleteJson("/api/v1/roles/{$roleId}")->assertConflict()->assertJsonPath('code', 'ROLE_IN_USE');
    }

    public function test_support_session_sees_projects_read_only(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $id = $this->createProject($owner);
        $support = $this->platformUser();

        $grant = $this->actingAs($owner)->postJson('/api/v1/support-grants', ['reason' => 'Dúvida sobre obras, pedido 42', 'hours' => 2])
            ->assertCreated()->json('data.id');
        $asSupport = fn () => $this->actingAs($support)->withHeaders($this->spaHeaders);
        $asSupport()->postJson("/api/v1/platform/support-grants/{$grant}/enter")->assertOk();

        $asSupport()->getJson('/api/v1/projects')->assertJsonPath('meta.total', 1);
        $asSupport()->getJson("/api/v1/projects/{$id}")->assertJsonPath('data.permissions', fn (array $p) => collect($p)->sort()->values()->all() === collect(PermissionRegistry::keys())
            ->filter(fn ($k) => PermissionRegistry::isProjectScoped($k) && str_ends_with($k, '.view'))->sort()->values()->all());
        $asSupport()->getJson("/api/v1/projects/{$id}/diary")->assertOk();
        $asSupport()->putJson("/api/v1/projects/{$id}", ['name' => 'X'])->assertForbidden();
    }
}
