<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HoldPointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $membership = app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membership])->assertCreated();

        return $user;
    }

    /** Pede, começa, verifica tudo conforme e submete. */
    private function runToSubmitted(string $base, int $id): void
    {
        $this->postJson("{$base}/{$id}/actions/request")->assertOk();
        foreach ($this->postJson("{$base}/{$id}/actions/start")->json('data.items') as $item) {
            $this->patchJson("{$base}/{$id}/items/{$item['id']}", ['result' => 'ok'])->assertOk();
        }
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();
    }

    public function test_hold_point_holds_work_until_released_after_approval(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $template = $this->postJson('/api/v1/checklist-templates', ['name' => 'Armaduras antes de betonar', 'hold_point' => true, 'items' => [['text' => 'Armaduras']]])
            ->assertCreated()->assertJsonPath('data.hold_point', true)->json('data.id');
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $building = $this->postJson("/api/v1/projects/{$project}/locations", ['kind' => 'building', 'name' => 'Bloco A'])->json('data.id');
        $slab = $this->postJson("/api/v1/projects/{$project}/elements", ['location_id' => $building, 'element_type' => 'slab'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";

        // O ponto de paragem vem da lista; a estrutura mostra o elemento parado.
        $id = $this->postJson($base, ['checklist_template_id' => $template, 'element_id' => $slab])->assertCreated()
            ->assertJsonPath('data.hold_point', true)->assertJsonPath('data.holding_work', true)->json('data.id');
        $this->getJson("/api/v1/projects/{$project}/structure")->assertJsonPath('data.holds.0.element_id', $slab)->assertJsonPath('data.holds.0.number', 1);

        $inspector = $this->teamMember($owner, $tenant, $project, 'fiscal');
        $this->actingAs($owner);
        $this->runToSubmitted($base, $id);

        // Antes de aprovada não se liberta; aprovada continua parada até alguém libertar.
        $this->actingAs($inspector)->postJson("{$base}/{$id}/release-hold")->assertConflict()->assertJsonPath('code', 'INSPECTION_STATUS');
        $this->postJson("{$base}/{$id}/actions/approve")->assertOk()->assertJsonPath('data.holding_work', true)->assertJsonPath('data.can.release_hold', true);
        $this->getJson("{$base}?group=holds")->assertJsonPath('meta.total', 1);

        // Quem não tem a permissão (técnico de qualidade) não liberta.
        $quality = $this->teamMember($owner, $tenant, $project, 'tecnico_qualidade');
        $this->actingAs($quality)->postJson("{$base}/{$id}/release-hold")->assertForbidden();

        $this->actingAs($inspector)->postJson("{$base}/{$id}/release-hold", ['note' => 'Pode betonar.'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.holding_work', false)
            ->assertJsonPath('data.hold_released_by', $inspector->name)
            ->assertJsonPath('data.events.5.label', 'Ponto de paragem libertado')
            ->assertJsonPath('data.events.5.reason', 'Pode betonar.')
            ->assertJsonPath('data.chain.valid', true);
        $this->postJson("{$base}/{$id}/release-hold")->assertConflict()->assertJsonPath('code', 'NO_HOLD_POINT');
        $this->getJson("/api/v1/projects/{$project}/structure")->assertJsonCount(0, 'data.holds');
    }

    public function test_reinspection_carries_the_hold_point_and_cancelled_releases_nothing(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";

        $id = $this->postJson($base, ['hold_point' => true, 'items' => [['text' => 'Impermeabilização']]])->json('data.id');
        $this->runToSubmitted($base, $id);
        $this->postJson("{$base}/{$id}/actions/reject", ['reason' => 'Telas mal sobrepostas'])->assertOk()->assertJsonPath('data.can.release_hold', false);

        // A reinspeção leva o ponto de paragem; a rejeitada deixa de contar.
        $copy = $this->postJson("{$base}/{$id}/reinspection")->assertCreated()->assertJsonPath('data.hold_point', true)->json('data.id');
        $holds = $this->getJson("{$base}?group=holds")->assertJsonPath('meta.total', 1)->json('data');
        $this->assertSame($copy, $holds[0]['id']);

        // Em rascunho pode tirar-se; cancelada não segura nada.
        $this->putJson("{$base}/{$copy}", ['hold_point' => false])->assertOk()->assertJsonPath('data.holding_work', false);
        $this->putJson("{$base}/{$copy}", ['hold_point' => true])->assertOk();
        $this->postJson("{$base}/{$copy}/actions/request")->assertOk();
        $this->postJson("{$base}/{$copy}/actions/cancel", ['reason' => 'Trabalho adiado'])->assertOk()->assertJsonPath('data.holding_work', false);
        $this->getJson("{$base}?group=holds")->assertJsonPath('meta.total', 0);
    }
}
