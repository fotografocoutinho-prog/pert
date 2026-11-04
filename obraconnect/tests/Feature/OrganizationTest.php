<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class OrganizationTest extends TestCase
{
    private function membershipId(Tenant $tenant, User $user): int
    {
        return app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $user->id)->value('id'));
    }

    public function test_departments_and_who_is_in_each(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $ana = $this->addMember($tenant, 'diretor_obra');
        $rui = $this->addMember($tenant, 'encarregado');
        [$anaId, $ruiId] = [$this->membershipId($tenant, $ana), $this->membershipId($tenant, $rui)];
        $this->actingAs($owner);

        $production = $this->postJson('/api/v1/departments', ['name' => ' Produção ', 'description' => 'Obras em curso'])
            ->assertCreated()->assertJsonPath('data.name', 'Produção')->json('data.id');
        $budgets = $this->postJson('/api/v1/departments', ['name' => 'Orçamentação'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/departments', ['name' => 'Produção'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->putJson("/api/v1/departments/{$production}/members", ['membership_ids' => [$anaId, $ruiId]])
            ->assertOk()->assertJsonPath('data.members_count', 2);
        // Passar para outro departamento tira do anterior.
        $this->putJson("/api/v1/departments/{$budgets}/members", ['membership_ids' => [$ruiId]])->assertOk();
        $departments = collect($this->getJson('/api/v1/departments')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([$anaId], $departments['Produção']['membership_ids']);
        $this->assertSame([$ruiId], $departments['Orçamentação']['membership_ids']);
        $this->assertTrue(AuditLog::query()->where('event', 'department.members_changed')->exists());

        // Na lista de utilizadores: departamento de cada um e filtro.
        $this->getJson("/api/v1/members?department_id={$budgets}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.department.name', 'Orçamentação');
        $this->getJson('/api/v1/members?department_id=none')->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $owner->id);

        // Apagar um departamento deixa as pessoas sem departamento.
        $this->deleteJson("/api/v1/departments/{$production}")->assertNoContent();
        $this->getJson('/api/v1/members?department_id=none')->assertJsonCount(2, 'data');

        // Pessoa de outra empresa não entra.
        [, $tenantB] = $this->createCompany('B');
        $stranger = $this->addMember($tenantB, 'encarregado');
        $this->putJson("/api/v1/departments/{$budgets}/members", ['membership_ids' => [$this->membershipId($tenantB, $stranger)]])
            ->assertUnprocessable();
    }

    public function test_cost_centers_tree_codes_and_archive(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);

        $works = $this->postJson('/api/v1/cost-centers', ['name' => 'Obras'])->assertCreated()->assertJsonPath('data.code', '1')->json('data.id');
        $overheads = $this->postJson('/api/v1/cost-centers', ['name' => 'Estrutura'])->assertJsonPath('data.code', '2')->json('data.id');
        $house = $this->postJson('/api/v1/cost-centers', ['name' => 'Moradia Costa', 'parent_id' => $works])->assertJsonPath('data.code', '1.01')->json('data.id');
        $this->postJson('/api/v1/cost-centers', ['name' => 'Armazém', 'parent_id' => $overheads, 'code' => 'ARM'])->assertJsonPath('data.code', 'ARM');
        $this->postJson('/api/v1/cost-centers', ['name' => 'Repetido', 'code' => 'arm '])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/cost-centers', ['name' => 'Mau', 'code' => 'a b'])->assertUnprocessable()->assertJsonValidationErrors('code');

        // Não pode ficar dentro de si próprio nem de um descendente.
        $this->putJson("/api/v1/cost-centers/{$works}", ['parent_id' => $house])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->putJson("/api/v1/cost-centers/{$works}", ['parent_id' => $works])->assertUnprocessable();

        // Ligado a uma obra: não se apaga, arquiva-se; arquivado não se escolhe de novo.
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia Costa', 'cost_center_id' => $house])
            ->assertCreated()->assertJsonPath('data.cost_center.code', '1.01')->json('data.id');
        $this->deleteJson("/api/v1/cost-centers/{$house}")->assertConflict()->assertJsonPath('code', 'COST_CENTER_IN_USE');
        $this->deleteJson("/api/v1/cost-centers/{$works}")->assertConflict();
        $this->putJson("/api/v1/cost-centers/{$house}", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);
        $this->putJson("/api/v1/projects/{$project}", ['name' => 'Moradia Costa (fase 2)', 'cost_center_id' => $house])->assertOk();
        $this->postJson('/api/v1/projects', ['name' => 'Outra', 'cost_center_id' => $house])->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
        $this->postJson('/api/v1/cost-centers', ['name' => 'Dentro de arquivado', 'parent_id' => $house])->assertUnprocessable();

        $list = collect($this->getJson('/api/v1/cost-centers')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['1', '1.01', '2', 'ARM'], $list->pluck('code')->all());
        $this->assertSame(1, $list['1.01']['projects_count']);
        $this->assertSame(1, $list['1']['children_count']);
        $this->assertTrue(AuditLog::query()->where('event', 'cost_center.archived')->exists());

        $this->putJson("/api/v1/projects/{$project}", ['cost_center_id' => null])->assertOk();
        $this->putJson("/api/v1/cost-centers/{$house}", ['archived' => false])->assertOk();
        $this->deleteJson("/api/v1/cost-centers/{$house}")->assertNoContent();
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $center = $this->actingAs($owner)->postJson('/api/v1/cost-centers', ['name' => 'Obras'])->json('data.id');
        $department = $this->postJson('/api/v1/departments', ['name' => 'Produção'])->json('data.id');

        $foreman = $this->addMember($tenant, 'encarregado');
        $this->actingAs($foreman)->getJson('/api/v1/departments')->assertOk();
        $this->actingAs($foreman)->getJson('/api/v1/cost-centers')->assertOk();
        $this->actingAs($foreman)->postJson('/api/v1/departments', ['name' => 'X'])->assertForbidden();
        $this->actingAs($foreman)->postJson('/api/v1/cost-centers', ['name' => 'X'])->assertForbidden();

        $technical = $this->addMember($tenant, 'diretor_tecnico');
        $this->actingAs($technical)->postJson('/api/v1/cost-centers', ['name' => 'Estrutura'])->assertCreated();
        $this->actingAs($technical)->putJson("/api/v1/departments/{$department}/members", ['membership_ids' => []])->assertForbidden();

        $clerk = $this->addMember($tenant, 'administrativo');
        $this->actingAs($clerk)->putJson("/api/v1/departments/{$department}/members", ['membership_ids' => [$this->membershipId($tenant, $foreman)]])->assertOk();

        $sub = $this->addMember($tenant, 'subempreiteiro');
        $this->actingAs($sub)->getJson('/api/v1/cost-centers')->assertForbidden();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->putJson("/api/v1/cost-centers/{$center}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($ownerB)->deleteJson("/api/v1/departments/{$department}")->assertNotFound();
        $this->actingAs($ownerB)->postJson('/api/v1/projects', ['name' => 'Obra B', 'cost_center_id' => $center])
            ->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
    }

    public function test_departments_and_cost_centers_are_in_the_data_export(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $department = $this->postJson('/api/v1/departments', ['name' => 'Produção'])->json('data.id');
        $this->putJson("/api/v1/departments/{$department}/members", ['membership_ids' => [$this->membershipId($tenant, $owner)]])->assertOk();
        $this->postJson('/api/v1/cost-centers', ['name' => 'Obras'])->assertCreated();

        $exportId = $this->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $departments = json_decode($zip->getFromName('departments.json'), true);
        $centers = json_decode($zip->getFromName('cost_centers.json'), true);
        $members = json_decode($zip->getFromName('members.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('Produção', $departments[0]['name']);
        $this->assertSame('1', $centers[0]['code']);
        $this->assertSame('Produção', $members[0]['department']);
    }
}
