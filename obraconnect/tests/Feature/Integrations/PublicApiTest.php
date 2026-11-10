<?php

namespace Tests\Feature\Integrations;

use App\Actions\Tenancy\RegisterCompany;
use App\Enums\TenantStatus;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('obraconnect.files.disk'));
        config(['obraconnect.docintel.url' => null, 'obraconnect.docintel.key' => null]);
    }

    /** @return array{0: User, 1: Tenant} */
    private function company(string $name = 'Empresa A', string $plan = 'business'): array
    {
        $owner = User::factory()->create();
        $tenant = app(RegisterCompany::class)->handle($owner, ['name' => $name], Plan::query()->where('code', $plan)->firstOrFail());

        return [$owner->refresh(), $tenant];
    }

    private function newKey(User $owner, array $scopes, string $name = 'Faturação'): string
    {
        $this->actingAs($owner);

        return $this->postJson('/api/v1/integrations/api-keys', ['name' => $name, 'scopes' => $scopes])
            ->assertCreated()->assertJsonPath('data.name', $name)->assertJsonPath('data.scopes', $scopes)->json('data.plain_key');
    }

    private function bearer(string $key): array
    {
        return ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];
    }

    public function test_keys_only_see_their_company_and_their_scopes(): void
    {
        [$ownerA] = $this->company('Construções Alfa');
        [$ownerB] = $this->company('Construções Beta');
        $this->actingAs($ownerB);
        $projectB = $this->postJson('/api/v1/projects', ['name' => 'Obra da Beta', 'starts_on' => '2026-11-02'])->assertCreated()->json('data.id');

        $this->actingAs($ownerA);
        $projectA = $this->postJson('/api/v1/projects', ['name' => 'Moradia em Braga', 'starts_on' => '2026-11-02', 'contract_value_cents' => 25000000])->assertCreated()->json('data');
        $key = $this->newKey($ownerA, ['projects:read']);
        $this->assertStringStartsWith('oc_', $key);
        $this->assertSame(43, strlen($key));

        // Só o hash fica guardado; a listagem não mostra a chave.
        $stored = ApiKey::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(hash('sha256', $key), $stored->key_hash);
        $this->assertSame(substr($key, 0, 12), $stored->prefix);
        $index = $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonCount(1, 'data.api_keys');
        $this->assertStringNotContainsString($key, $index->getContent());
        $this->assertArrayNotHasKey('plain_key', $index->json('data.api_keys.0'));

        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertOk()
            ->assertJsonPath('data.company', 'Construções Alfa')->assertJsonPath('data.scopes', ['projects:read']);
        $this->getJson('/api/public/v1/projects', $this->bearer($key))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $projectA['id'])->assertJsonPath('meta.total', 1);
        $this->getJson("/api/public/v1/projects/{$projectA['code']}", $this->bearer($key))->assertOk()
            ->assertJsonPath('data.name', 'Moradia em Braga')->assertJsonPath('data.costs.contract_cents', 25000000)
            ->assertJsonStructure(['data' => ['costs' => ['actual_cents', 'committed_cents'], 'forecast' => ['as_of', 'cost', 'schedule']]]);
        $this->getJson("/api/public/v1/projects/{$projectA['id']}/certificates", $this->bearer($key))->assertOk()->assertJsonCount(0, 'data');

        // A obra da outra empresa não existe para esta chave.
        $this->getJson("/api/public/v1/projects/{$projectB}", $this->bearer($key))->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        // Âmbito em falta.
        $this->getJson('/api/public/v1/invoice-drafts', $this->bearer($key))->assertForbidden()->assertJsonPath('code', 'API_SCOPE_MISSING');
        $this->getJson('/api/public/v1/supplier-documents/1', $this->bearer($key))->assertForbidden();

        // Sem chave, chave errada, chave com o início certo e o resto errado, X-API-Key.
        $this->getJson('/api/public/v1/projects')->assertUnauthorized()->assertJsonPath('code', 'API_KEY_INVALID');
        $this->getJson('/api/public/v1/projects', $this->bearer('oc_'.str_repeat('x', 40)))->assertUnauthorized();
        $this->getJson('/api/public/v1/projects', $this->bearer(substr($key, 0, 12).str_repeat('y', 31)))->assertUnauthorized();
        $this->getJson('/api/public/v1/projects', ['X-API-Key' => $key, 'Accept' => 'application/json'])->assertOk();

        // O uso fica registado; revogada, deixa de servir.
        $this->assertNotNull($stored->refresh()->last_used_at);
        $this->actingAs($ownerA)->deleteJson("/api/v1/integrations/api-keys/{$stored->id}")->assertNoContent();
        $this->getJson('/api/public/v1/projects', $this->bearer($key))->assertUnauthorized();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('event', 'integrations.key_revoked')->count());

        // A outra empresa não consegue revogar chaves desta.
        $other = $this->newKey($ownerA, ['projects:read'], 'BI');
        $otherId = ApiKey::query()->withoutGlobalScopes()->where('prefix', substr($other, 0, 12))->value('id');
        $this->actingAs($ownerB)->deleteJson("/api/v1/integrations/api-keys/{$otherId}")->assertNotFound();
        $this->getJson('/api/public/v1/ping', $this->bearer($other))->assertOk();
    }

    public function test_expired_keys_plan_without_integrations_and_suspended_companies(): void
    {
        [$owner, $tenant] = $this->company();
        $key = $this->newKey($owner, ['projects:read']);

        ApiKey::query()->withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertUnauthorized();
        ApiKey::query()->withoutGlobalScopes()->update(['expires_at' => now()->addDay()]);
        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertOk();

        $tenant->forceFill(['status' => TenantStatus::ReadOnly])->save();
        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertOk();
        $this->post('/api/public/v1/supplier-documents', [], $this->bearer($key))->assertStatus(423)->assertJsonPath('code', 'TENANT_READ_ONLY');
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertStatus(423)->assertJsonPath('code', 'TENANT_SUSPENDED');
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        // Plano sem integrações: a chave deixa de funcionar e não se criam outras.
        DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['plan_id' => Plan::query()->where('code', 'start')->value('id')]);
        $this->getJson('/api/public/v1/ping', $this->bearer($key))->assertStatus(402)->assertJsonPath('code', 'PLAN_FEATURE_REQUIRED');
        $this->actingAs($owner)->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['projects:read']])->assertStatus(402);
    }

    public function test_only_people_with_the_permission_manage_integrations(): void
    {
        [$owner, $tenant] = $this->company();
        $director = $this->addMember($tenant, 'diretor_tecnico');

        $this->actingAs($director)->getJson('/api/v1/integrations')->assertForbidden();
        $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['projects:read']])->assertForbidden();

        $this->actingAs($owner)->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => []])->assertUnprocessable()->assertJsonValidationErrors('scopes');
        $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['tudo:escrever']])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');
        $this->postJson('/api/v1/integrations/api-keys', ['name' => 'X', 'scopes' => ['projects:read'], 'expires_at' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_supplier_documents_can_be_sent_and_read_through_the_api(): void
    {
        [$owner] = $this->company();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'starts_on' => '2026-11-02'])->assertCreated()->json('data');
        $writer = $this->newKey($owner, ['supplier-documents:write'], 'Digitalizadora');
        $reader = $this->newKey($owner, ['supplier-documents:read'], 'Contabilidade');
        $pdf = UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n% teste\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");

        $this->post('/api/public/v1/supplier-documents', ['file' => $pdf, 'project_code' => 'NAO-EXISTE'], $this->bearer($writer))
            ->assertUnprocessable()->assertJsonPath('code', 'PROJECT_NOT_FOUND');
        $id = $this->post('/api/public/v1/supplier-documents', ['file' => $pdf, 'project_code' => $project['code']], $this->bearer($writer))
            ->assertCreated()->assertJsonPath('data.status', 'to_review')->assertJsonPath('data.project_code', $project['code'])
            ->assertJsonPath('data.original_name', 'fatura.pdf')->json('data.id');
        // O mesmo ficheiro outra vez: repetido.
        $this->post('/api/public/v1/supplier-documents', ['file' => $pdf], $this->bearer($writer))->assertConflict();

        $this->getJson("/api/public/v1/supplier-documents/{$id}", $this->bearer($writer))->assertForbidden();
        $this->getJson("/api/public/v1/supplier-documents/{$id}", $this->bearer($reader))->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/public/v1/supplier-documents/999999', $this->bearer($reader))->assertNotFound();

        $audit = AuditLog::query()->withoutGlobalScopes()->where('event', 'supplier_document.uploaded')->firstOrFail();
        $this->assertSame('api:Digitalizadora', $audit->properties['via'] ?? null);
        $this->assertNull($audit->user_id);
    }
}
