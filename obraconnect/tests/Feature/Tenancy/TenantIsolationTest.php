<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\ExportTenantData;
use App\Models\Membership;
use App\Models\Role;
use App\Models\TenantExport;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_tenant_models_fail_closed_without_context(): void
    {
        $this->createCompany();

        $this->expectException(MissingTenantContextException::class);
        Role::query()->count();
    }

    public function test_creating_tenant_model_without_context_fails(): void
    {
        $this->expectException(MissingTenantContextException::class);
        Role::query()->create(['key' => 'x', 'name' => 'X']);
    }

    public function test_queries_are_scoped_to_the_current_tenant(): void
    {
        [, $a] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');
        $context = app(TenantContext::class);

        $rolesA = $context->runAs($a, fn () => Role::query()->pluck('tenant_id')->unique()->all());
        $rolesB = $context->runAs($b, fn () => Role::query()->pluck('tenant_id')->unique()->all());

        $this->assertSame([$a->id], $rolesA);
        $this->assertSame([$b->id], $rolesB);
        $this->assertNull($context->runAs($a, fn () => Role::query()->find($this->roleId($b, 'admin'))));
    }

    public function test_cannot_create_a_record_for_another_tenant(): void
    {
        [, $a] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->expectException(LogicException::class);

        app(TenantContext::class)->runAs($a, function () use ($b): void {
            $role = new Role(['key' => 'intruso', 'name' => 'Intruso']);
            $role->tenant_id = $b->id;
            $role->save();
        });
    }

    public function test_tenant_id_cannot_be_changed(): void
    {
        [, $a] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->expectException(LogicException::class);

        app(TenantContext::class)->runAs($a, function () use ($b): void {
            $role = Role::query()->where('key', 'fiscal')->firstOrFail();
            $role->tenant_id = $b->id;
            $role->save();
        });
    }

    public function test_database_rejects_membership_with_role_from_another_tenant(): void
    {
        [, $a] = $this->createCompany('A');
        [$ownerB, $b] = $this->createCompany('B');

        $this->expectException(QueryException::class);

        // Inserção direta, sem passar pela aplicação: a FK composta tem de recusar.
        DB::table('memberships')->insert([
            'tenant_id' => $a->id,
            'user_id' => $ownerB->id,
            'role_id' => $this->roleId($b, 'admin'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_api_only_lists_members_of_the_active_company(): void
    {
        [$ownerA, $a] = $this->createCompany('A');
        [$ownerB] = $this->createCompany('B');

        $response = $this->actingAs($ownerA)->getJson('/api/v1/members')->assertOk();

        $emails = collect($response->json('data'))->pluck('user.email');
        $this->assertContains($ownerA->email, $emails);
        $this->assertNotContains($ownerB->email, $emails);
    }

    public function test_route_binding_does_not_resolve_records_of_another_company(): void
    {
        [$ownerA] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $membershipB = app(TenantContext::class)->runAs($b, fn () => Membership::query()->firstOrFail());

        $this->actingAs($ownerA)
            ->patchJson("/api/v1/members/{$membershipB->id}", ['job_title' => 'Hacker'])
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->putJson('/api/v1/roles/'.$this->roleId($b, 'fiscal'), ['name' => 'X', 'permissions' => []])
            ->assertNotFound();
    }

    public function test_role_ids_of_another_company_are_rejected_by_validation(): void
    {
        Mail::fake();
        [$ownerA] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->actingAs($ownerA)
            ->postJson('/api/v1/invitations', ['email' => 'novo@exemplo.pt', 'role_id' => $this->roleId($b, 'fiscal')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');
    }

    public function test_switching_to_a_company_without_membership_is_forbidden(): void
    {
        [$ownerA] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->actingAs($ownerA)
            ->postJson("/api/v1/me/tenants/{$b->id}/switch")
            ->assertForbidden()
            ->assertJson(['code' => 'NOT_A_MEMBER']);

        $this->assertNotSame($b->id, $ownerA->refresh()->current_tenant_id);
    }

    public function test_user_in_two_companies_can_switch_between_them(): void
    {
        [$ownerA, $a] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');
        $this->addMember($b, 'fiscal', $ownerA);
        $ownerA->forceFill(['current_tenant_id' => $a->id])->save();

        $this->actingAs($ownerA)->getJson('/api/v1/company')->assertJsonPath('data.name', 'A');

        $this->actingAs($ownerA)->postJson("/api/v1/me/tenants/{$b->id}/switch")->assertOk();

        // Na empresa B é fiscal: vê a empresa mas não os utilizadores.
        $this->actingAs($ownerA)->getJson('/api/v1/company')->assertJsonPath('data.name', 'B');
        $this->actingAs($ownerA)->getJson('/api/v1/members')->assertForbidden();
    }

    public function test_tenant_cannot_be_chosen_through_request_parameters(): void
    {
        [$ownerA] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->actingAs($ownerA)
            ->withHeaders(['X-Tenant-Id' => (string) $b->id])
            ->getJson("/api/v1/company?tenant_id={$b->id}")
            ->assertJsonPath('data.name', 'A');
    }

    public function test_queued_jobs_restore_the_tenant_context(): void
    {
        Storage::fake('private');
        Mail::fake();
        config(['queue.default' => 'database']);

        [$ownerA, $a] = $this->createCompany('A');
        $this->createCompany('B');

        $export = app(TenantContext::class)->runAs($a, function () use ($ownerA) {
            $export = TenantExport::query()->create(['requested_by_user_id' => $ownerA->id, 'status' => 'pending', 'disk' => 'private']);
            ExportTenantData::dispatch($export->id);

            return $export;
        });

        // Simula um worker noutro processo: sem empresa nem Context.
        app(TenantContext::class)->clear();
        Context::flush();

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--stop-when-empty' => true]);

        $this->assertSame('completed', DB::table('tenant_exports')->where('id', $export->id)->value('status'));
    }
}
