<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\SupportAccessGrant;
use App\Tenancy\TenantContext;
use Tests\TestCase;

class SupportAccessTest extends TestCase
{
    public function test_full_support_access_flow_is_read_only_and_audited(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $support = $this->platformUser(PlatformRole::Support);

        // Sem autorização, o suporte não entra.
        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/platform/support-grants/999/enter')
            ->assertForbidden();

        $grantId = $this->actingAs($owner)->postJson('/api/v1/support-grants', [
            'reason' => 'Erro ao convidar utilizadores, pedido #123',
            'hours' => 2,
        ])->assertCreated()->json('data.id');

        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/platform/support-grants')
            ->assertOk()->assertJsonPath('data.0.tenant.id', $tenant->id);

        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->postJson("/api/v1/platform/support-grants/{$grantId}/enter")
            ->assertOk();

        // Leitura permitida, escrita recusada.
        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/members')->assertOk();
        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->putJson('/api/v1/company', ['name' => 'Alterado', 'country_code' => 'PT'])
            ->assertForbidden()->assertJson(['code' => 'SUPPORT_READ_ONLY']);
        // Exportar dados não é uma permissão de consulta.
        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/exports')->assertForbidden();

        $this->assertTrue(AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('event', 'support.request')
            ->where('actor_type', 'support')
            ->exists());

        // A empresa revoga: o acesso termina no pedido seguinte.
        $this->actingAs($owner)->deleteJson("/api/v1/support-grants/{$grantId}")->assertNoContent();

        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/members')
            ->assertForbidden()->assertJson(['code' => 'SUPPORT_GRANT_INVALID']);
    }

    public function test_expired_grant_cannot_be_used(): void
    {
        [, $tenant] = $this->createCompany();
        $support = $this->platformUser(PlatformRole::Support);

        $grant = app(TenantContext::class)->runAs($tenant, fn () => SupportAccessGrant::query()->create([
            'reason' => 'Pedido antigo de suporte',
            'access_level' => 'read',
            'expires_at' => now()->subMinute(),
        ]));

        $this->actingAs($support)->withHeaders($this->spaHeaders)
            ->postJson("/api/v1/platform/support-grants/{$grant->id}/enter")
            ->assertForbidden()->assertJson(['code' => 'SUPPORT_GRANT_INVALID']);
    }

    public function test_grant_duration_is_limited(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)->postJson('/api/v1/support-grants', ['reason' => 'Motivo suficientemente longo', 'hours' => 500])
            ->assertJsonValidationErrors('hours');
    }

    public function test_billing_staff_cannot_use_support_access(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);

        $this->actingAs($billing)->getJson('/api/v1/platform/support-grants')->assertForbidden();
    }
}
