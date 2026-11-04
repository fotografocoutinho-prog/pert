<?php

namespace Tests\Feature\Projects;

use App\Models\InspectionEvent;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;
use ZipArchive;

class InspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function membershipId(Tenant $tenant, User $user): int
    {
        return app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
    }

    /** Pessoa com o perfil indicado, já na equipa da obra. */
    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $this->membershipId($tenant, $user)])->assertCreated();

        return $user;
    }

    private function template(): array
    {
        return [
            'name' => 'Betonagem de lajes',
            'items' => [
                ['text' => 'Armaduras conforme projeto', 'requires_photo' => true, 'min_photos' => 2],
                ['text' => 'Recobrimento das armaduras', 'requires_measure' => true, 'measure_unit' => 'mm', 'measure_min' => 25, 'measure_max' => 40],
                ['text' => 'Cofragem limpa e estanque', 'guidance' => 'Ver juntas e travamentos'],
            ],
        ];
    }

    private function photo(User $user, int $project, int $inspection, int $item)
    {
        return $this->actingAs($user)->post(
            "/api/v1/projects/{$project}/inspections/{$inspection}/items/{$item}/photos",
            ['photo' => UploadedFile::fake()->image('armadura.jpg', 120, 90)],
            ['Accept' => 'application/json'],
        );
    }

    public function test_checklist_templates_are_versioned_and_archived_instead_of_deleted_when_used(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);

        $id = $this->postJson('/api/v1/checklist-templates', $this->template())->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.items_count', 3)
            ->assertJsonPath('data.items.0.min_photos', 2)
            ->assertJsonPath('data.items.1.measure_min', 25)
            // Sem medição exigida não ficam unidade nem limites; sem fotografia, mínimo 0.
            ->assertJsonPath('data.items.2.min_photos', 0)
            ->json('data.id');
        $this->postJson('/api/v1/checklist-templates', ['name' => ' betonagem DE LAJES ', 'items' => [['text' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/checklist-templates', ['name' => 'Sem pontos', 'items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/checklist-templates', ['name' => 'Limites trocados', 'items' => [['text' => 'x', 'requires_measure' => true, 'measure_min' => 5, 'measure_max' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.measure_max');

        // Os mesmos pontos não sobem a versão; pontos diferentes sobem.
        $this->putJson("/api/v1/checklist-templates/{$id}", ['items' => $this->template()['items']])->assertOk()->assertJsonPath('data.version', 1);
        $changed = $this->template()['items'];
        $changed[] = ['text' => 'Negativos para tubagens'];
        $this->putJson("/api/v1/checklist-templates/{$id}", ['items' => $changed])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.items_count', 4);

        // Usado numa inspeção: não se apaga, arquiva-se; arquivado não se escolhe.
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $this->postJson("/api/v1/projects/{$project}/inspections", ['checklist_template_id' => $id])->assertCreated();
        $this->deleteJson("/api/v1/checklist-templates/{$id}")->assertConflict()->assertJsonPath('code', 'CHECKLIST_IN_USE');
        $this->putJson("/api/v1/checklist-templates/{$id}", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson("/api/v1/projects/{$project}/inspections", ['checklist_template_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('checklist_template_id');

        // Quem trabalha com inspeções consulta os modelos; só checklists.manage os altera.
        $foreman = $this->addMember($tenant, 'encarregado');
        $this->actingAs($foreman)->getJson('/api/v1/checklist-templates')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($foreman)->postJson('/api/v1/checklist-templates', $this->template())->assertForbidden();
        $this->actingAs($this->addMember($tenant, 'tecnico_qualidade'))->postJson('/api/v1/checklist-templates', ['name' => 'Alvenarias', 'items' => [['text' => 'Prumo']]])->assertCreated();
        $this->actingAs($this->addMember($tenant, 'trabalhador'))->getJson('/api/v1/checklist-templates')->assertForbidden();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("/api/v1/checklist-templates/{$id}")->assertNotFound();
    }

    public function test_full_workflow_with_evidence_decision_and_reinspection(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $templateId = $this->postJson('/api/v1/checklist-templates', $this->template())->json('data.id');
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $inspector = $this->teamMember($owner, $tenant, $project, 'fiscal');
        $base = "/api/v1/projects/{$project}/inspections";

        // Encarregado prepara a partir do modelo: os pontos são copiados.
        $id = $this->actingAs($foreman)->postJson($base, ['checklist_template_id' => $templateId, 'remote' => true])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Betonagem de lajes')
            ->assertJsonPath('data.template.version', 1)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.events.0.label', 'Criada')
            ->assertJsonPath('data.can.edit', true)
            ->json('data.id');
        $this->putJson("{$base}/{$id}", ['title' => 'Laje do piso 1', 'items' => [...$this->template()['items'], ['text' => 'Juntas de betonagem']]])
            ->assertOk()->assertJsonCount(4, 'data.items');

        // Mudar o modelo depois não mexe na inspeção.
        $this->actingAs($owner)->putJson("/api/v1/checklist-templates/{$templateId}", ['items' => [['text' => 'Outro']]])->assertJsonPath('data.version', 2);
        $this->actingAs($foreman)->getJson("{$base}/{$id}")->assertJsonCount(4, 'data.items')->assertJsonPath('data.items.0.text', 'Armaduras conforme projeto');

        // O encarregado pede; não faz a inspeção.
        $this->postJson("{$base}/{$id}/actions/request")->assertOk()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.can.edit', false);
        $this->postJson("{$base}/{$id}/actions/start")->assertForbidden();
        $this->putJson("{$base}/{$id}", ['title' => 'Mudança'])->assertConflict()->assertJsonPath('code', 'INSPECTION_STATUS');

        // Fiscal agenda e começa.
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/schedule", ['scheduled_for' => now()->subDay()->format('Y-m-d H:i')])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
        $this->postJson("{$base}/{$id}/actions/schedule", ['scheduled_for' => now()->addDay()->format('Y-m-d').' 09:30'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.inspector', $inspector->name)
            ->assertJsonPath('data.events.2.scheduled_for', now()->addDay()->format('Y-m-d').' 09:30');
        $items = $this->postJson("{$base}/{$id}/actions/start")->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.can.fill', true)->json('data.items');
        [$rebar, $cover, $formwork, $joints] = array_column($items, 'id');

        // Sem evidências não se submete; a resposta diz o que falta.
        $this->postJson("{$base}/{$id}/actions/submit")->assertUnprocessable()->assertJsonPath('code', 'EVIDENCE_MISSING');

        $this->patchJson("{$base}/{$id}/items/{$rebar}", ['result' => 'ok'])->assertOk()->assertJsonPath('data.evidence.can_submit', false);
        $this->photo($inspector, $project, $id, $rebar)->assertCreated()->assertJsonPath('data.photo.status', 'ready');
        $this->photo($inspector, $project, $id, $rebar)->assertCreated();
        $this->actingAs($inspector)->patchJson("{$base}/{$id}/items/{$cover}", ['result' => 'ok', 'measure_value' => 20])
            ->assertJsonPath('data.item.out_of_tolerance', true);
        $this->patchJson("{$base}/{$id}/items/{$formwork}", ['result' => 'nok'])->assertOk();
        $response = $this->patchJson("{$base}/{$id}/items/{$joints}", ['result' => 'na'])->assertOk();
        // Não conformidade sem descrição ainda impede a submissão.
        $this->assertFalse($response->json('data.evidence.can_submit'));
        $this->assertSame([3], collect($response->json('data.evidence.criteria'))->firstWhere('key', 'nok_notes')['missing']);

        $evidence = $this->patchJson("{$base}/{$id}/items/{$formwork}", ['notes' => 'Junta aberta junto ao pilar P3'])->json('data.evidence');
        $this->assertTrue($evidence['can_submit']);
        // Recomendados em falta baixam o índice: NC sem fotografia, medição fora dos limites e (remota) pontos sem fotografia.
        $this->assertLessThan(100, $evidence['score']);
        $this->assertSame([2], collect($evidence['criteria'])->firstWhere('key', 'tolerance')['missing']);

        $submitted = $this->postJson("{$base}/{$id}/actions/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $this->assertSame($evidence['score'], $submitted['evidence_score']);
        $this->patchJson("{$base}/{$id}/items/{$rebar}", ['result' => 'nok'])->assertConflict()->assertJsonPath('code', 'INSPECTION_STATUS');
        $this->photo($inspector, $project, $id, $rebar)->assertConflict();

        // Decisão: com não conformidades não se aprova sem reservas; rejeitar exige justificação.
        $this->postJson("{$base}/{$id}/actions/approve")->assertConflict()->assertJsonPath('code', 'INSPECTION_HAS_NOK');
        $this->postJson("{$base}/{$id}/actions/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($foreman)->postJson("{$base}/{$id}/actions/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/reject", ['reason' => 'Cofragem por corrigir'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decided_by', $inspector->name)
            ->assertJsonPath('data.decision_note', 'Cofragem por corrigir')
            ->assertJsonPath('data.events.5.label', 'Rejeitada')
            ->assertJsonPath('data.chain.valid', true)
            ->assertJsonPath('data.can.actions', []);
        $this->postJson("{$base}/{$id}/actions/cancel", ['reason' => 'x'])->assertConflict();

        // Reinspeção: rascunho novo com os mesmos pontos, por verificar, ligado ao original.
        $copy = $this->actingAs($foreman)->postJson("{$base}/{$id}/reinspection")->assertCreated()
            ->assertJsonPath('data.number', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reinspection_of.number', 1)
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.items.0.result', null)
            ->assertJsonPath('data.items.0.photos', [])
            ->json('data.id');
        $this->getJson("{$base}/{$id}")->assertJsonPath('data.reinspections.0.number', 2)
            ->assertJsonPath('data.events.6.label', 'Pedida reinspeção')
            ->assertJsonPath('data.chain.valid', true);
        // Rascunhos apagam-se; a original (rejeitada) não.
        $this->deleteJson("{$base}/{$copy}")->assertNoContent();
        $this->deleteJson("{$base}/{$id}")->assertConflict();

        $this->getJson("{$base}?group=closed")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nok_count', 1)->assertJsonPath('data.0.answered_count', 4);
        $this->getJson("{$base}?group=open")->assertJsonPath('meta.total', 0);
    }

    public function test_return_approve_and_cancel(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";

        $id = $this->postJson($base, ['title' => 'Impermeabilização da cobertura', 'items' => [['text' => 'Sobreposição das telas']]])->json('data.id');
        $item = $this->getJson("{$base}/{$id}")->json('data.items.0.id');
        foreach (['request', 'start'] as $action) {
            $this->postJson("{$base}/{$id}/actions/{$action}")->assertOk();
        }
        $this->patchJson("{$base}/{$id}/items/{$item}", ['result' => 'ok'])->assertOk();
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk()->assertJsonPath('data.evidence_score', 100);

        // Devolvida volta a "em curso" e o índice deixa de contar.
        $this->postJson("{$base}/{$id}/actions/return", ['reason' => 'Falta a fotografia do remate'])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.evidence_score', null);
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();
        $this->postJson("{$base}/{$id}/actions/approve")->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.can.reinspect', false);
        $this->postJson("{$base}/{$id}/reinspection")->assertConflict();
        $this->postJson("{$base}/{$id}/actions/teleport")->assertNotFound();

        $other = $this->postJson($base, ['items' => [['text' => 'x']]])->assertCreated()->json('data.id');
        $this->postJson($base, [])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson("{$base}/{$other}/actions/cancel", ['reason' => 'x'])->assertConflict();
        $this->postJson("{$base}/{$other}/actions/request")->assertOk();
        $this->postJson("{$base}/{$other}/actions/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("{$base}/{$other}/actions/cancel", ['reason' => 'Trabalho adiado'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Obra arquivada: nada muda.
        $draft = $this->postJson($base, ['items' => [['text' => 'y']]])->json('data.id');
        $this->putJson("/api/v1/projects/{$project}", ['status' => 'archived'])->assertOk();
        $this->postJson("{$base}/{$draft}/actions/request")->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
        $this->getJson("{$base}/{$draft}")->assertOk()->assertJsonPath('data.can.edit', false)->assertJsonPath('data.can.actions', []);
    }

    public function test_access_follows_role_and_team(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";
        $id = $this->postJson($base, ['items' => [['text' => 'Prumo das paredes']]])->json('data.id');
        $this->postJson("{$base}/{$id}/actions/request")->assertOk();

        // Fiscal fora da equipa não vê a obra; na equipa faz e decide.
        $inspector = $this->addMember($tenant, 'fiscal');
        $this->actingAs($inspector)->getJson("{$base}/{$id}")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $this->membershipId($tenant, $inspector)])->assertCreated();
        $this->actingAs($inspector)->getJson("{$base}/{$id}")->assertOk()
            ->assertJsonPath('data.can.actions', fn (array $a) => collect($a)->pluck('key')->sort()->values()->all() === ['cancel', 'schedule', 'start']);
        $this->actingAs($inspector)->postJson($base, ['items' => [['text' => 'x']]])->assertForbidden();

        // Técnico de qualidade faz mas não decide; subempreiteiro na equipa pede.
        $quality = $this->teamMember($owner, $tenant, $project, 'tecnico_qualidade');
        $this->actingAs($quality)->postJson("{$base}/{$id}/actions/start")->assertOk();
        $item = $this->getJson("{$base}/{$id}")->json('data.items.0.id');
        $this->patchJson("{$base}/{$id}/items/{$item}", ['result' => 'ok'])->assertOk();
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();
        $this->postJson("{$base}/{$id}/actions/approve")->assertForbidden();
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/approve")->assertOk();

        $sub = $this->teamMember($owner, $tenant, $project, 'subempreiteiro');
        $this->actingAs($sub)->postJson($base, ['items' => [['text' => 'Pedido do subempreiteiro']]])->assertCreated();

        // Trabalhador na equipa, sem permissões de inspeção, não as vê.
        $worker = $this->teamMember($owner, $tenant, $project, 'trabalhador');
        $this->actingAs($worker)->getJson($base)->assertForbidden();
        $this->actingAs($worker)->getJson("/api/v1/projects/{$project}")->assertJsonPath('data.permissions', fn (array $p) => ! in_array('inspections.request', $p, true));

        // Outra empresa não chega lá.
        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("{$base}/{$id}")->assertNotFound();
    }

    public function test_history_chain_is_immutable_and_detects_tampering(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";
        $id = $this->postJson($base, ['items' => [['text' => 'Prumo']]])->json('data.id');
        $this->postJson("{$base}/{$id}/actions/request")->assertOk();
        $this->postJson("{$base}/{$id}/actions/cancel", ['reason' => 'Obra parada'])->assertOk();

        $events = $this->getJson("{$base}/{$id}")->assertJsonPath('data.chain.valid', true)->json('data.events');
        $this->assertCount(3, $events);
        $this->assertSame(64, strlen($events[2]['hash']));

        // Pelo modelo não se altera nem apaga.
        app(TenantContext::class)->runAs($tenant, function () use ($id): void {
            $event = InspectionEvent::query()->where('inspection_id', $id)->where('sequence', 2)->firstOrFail();
            try {
                $event->forceFill(['reason' => 'outra'])->save();
                $this->fail('Alterou o histórico.');
            } catch (LogicException) {
            }
            try {
                $event->delete();
                $this->fail('Apagou o histórico.');
            } catch (LogicException) {
            }
        });

        // Alterado diretamente na base de dados: a verificação aponta a linha.
        DB::table('inspection_events')->where('inspection_id', $id)->where('sequence', 3)->update(['reason' => 'Motivo trocado']);
        $this->getJson("{$base}/{$id}")->assertJsonPath('data.chain.valid', false)->assertJsonPath('data.chain.broken_at', 3);
    }

    public function test_evidence_photos_are_served_to_inspection_viewers_and_count_in_storage(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $building = $this->postJson("/api/v1/projects/{$project}/locations", ['kind' => 'building', 'name' => 'Bloco A'])->json('data.id');
        $column = $this->postJson("/api/v1/projects/{$project}/elements", ['location_id' => $building, 'element_type' => 'column'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";

        $id = $this->postJson($base, ['element_id' => $column, 'items' => [['text' => 'Pilar', 'requires_photo' => true]]])->assertCreated()
            ->assertJsonPath('data.location_id', $building)->json('data.id');
        $item = $this->getJson("{$base}/{$id}")->json('data.items.0.id');
        // Em rascunho ainda não se juntam evidências.
        $this->photo($owner, $project, $id, $item)->assertConflict();
        $this->postJson("{$base}/{$id}/actions/request");
        $this->postJson("{$base}/{$id}/actions/start");

        $used = (int) $tenant->fresh()->storage_used_bytes;
        $photo = $this->photo($owner, $project, $id, $item)->assertCreated()->json('data.photo');
        $this->assertGreaterThan($used, (int) $tenant->fresh()->storage_used_bytes);

        // Dono de obra na equipa (vê o diário, não as inspeções) não abre a evidência.
        $client = $this->teamMember($owner, $tenant, $project, 'dono_obra');
        $this->actingAs($client)->get($photo['urls']['thumb'])->assertForbidden();
        $this->actingAs($this->teamMember($owner, $tenant, $project, 'fiscal'))->get($photo['urls']['thumb'])->assertOk();

        // As evidências ficam na inspeção, não no histórico do elemento; o elemento não se apaga.
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/elements/{$column}/links")->assertJsonCount(0, 'data.photos');
        $this->deleteJson("/api/v1/projects/{$project}/elements/{$column}")->assertConflict()->assertJsonPath('code', 'ELEMENT_IN_USE');

        $this->deleteJson("{$base}/{$id}/items/{$item}/photos/{$photo['id']}")->assertOk()->assertJsonPath('data.evidence.can_submit', false);
        $this->assertSame($used, (int) $tenant->fresh()->storage_used_bytes);
    }

    public function test_inspections_and_templates_are_in_the_data_export(): void
    {
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $this->postJson('/api/v1/checklist-templates', $this->template())->assertCreated();
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $id = $this->postJson("/api/v1/projects/{$project}/inspections", ['items' => [['text' => 'Prumo']]])->json('data.id');
        $this->postJson("/api/v1/projects/{$project}/inspections/{$id}/actions/request")->assertOk();

        $exportId = $this->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);
        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $templates = json_decode($zip->getFromName('checklist_templates.json'), true);
        $inspections = json_decode($zip->getFromName('inspections.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('Betonagem de lajes', $templates[0]['name']);
        $this->assertCount(3, $templates[0]['items']);
        $this->assertSame('requested', $inspections[0]['status']);
        $this->assertCount(2, $inspections[0]['events']);
        $this->assertSame($inspections[0]['events'][0]['hash'], $inspections[0]['events'][1]['prev_hash']);
    }
}
