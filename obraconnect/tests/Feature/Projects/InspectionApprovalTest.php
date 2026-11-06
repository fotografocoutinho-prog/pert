<?php

namespace Tests\Feature\Projects;

use App\Mail\ProjectNoticeMail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InspectionApprovalTest extends TestCase
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

    /** Obra com encarregado (pede), fiscal e coordenador de segurança (fazem e decidem). */
    private function setUpProject(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');

        return [
            $owner,
            $project,
            $this->teamMember($owner, $tenant, $project, 'encarregado'),
            $this->teamMember($owner, $tenant, $project, 'fiscal'),
            $this->teamMember($owner, $tenant, $project, 'coordenador_seguranca'),
        ];
    }

    private function submitted(User $requester, User $inspector, string $base, array $payload, string $result = 'ok'): int
    {
        $id = $this->actingAs($requester)->postJson($base, $payload + ['items' => [['text' => 'Guarda-corpos montados']]])->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$id}/actions/request")->assertOk();
        $item = $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/start")->json('data.items.0.id');
        $this->patchJson("{$base}/{$id}/items/{$item}", ['result' => $result, 'notes' => $result === 'nok' ? 'Falta rodapé' : null])->assertOk();
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();

        return $id;
    }

    public function test_two_distinct_approvals_are_needed_and_recorded_in_the_chain(): void
    {
        [$owner, $project, $foreman, $inspector, $safety] = $this->setUpProject();
        $base = "/api/v1/projects/{$project}/inspections";

        // A lista pede duas aprovações; a inspeção herda.
        $template = $this->actingAs($owner)->postJson('/api/v1/checklist-templates', ['name' => 'Andaimes', 'approvals_required' => 2, 'items' => [['text' => 'x']]])
            ->assertCreated()->assertJsonPath('data.approvals_required', 2)->json('data.id');
        $this->actingAs($foreman)->postJson($base, ['checklist_template_id' => $template])->assertCreated()->assertJsonPath('data.approvals_required', 2);
        $this->postJson($base, ['approvals_required' => 4, 'items' => [['text' => 'x']]])->assertUnprocessable()->assertJsonValidationErrors('approvals_required');

        $id = $this->submitted($foreman, $inspector, $base, ['approvals_required' => 2]);

        // Primeira aprovação: continua em apreciação; quem já aprovou não aprova outra vez.
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/approve")->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.approvals.required', 2)
            ->assertJsonPath('data.approvals.given.0.user_name', $inspector->name)
            ->assertJsonPath('data.events.4.label', 'Aprovada (1 de 2)')
            ->assertJsonPath('data.can.actions', fn (array $a) => ! in_array('approve', array_column($a, 'key'), true));
        $this->postJson("{$base}/{$id}/actions/approve")->assertConflict()->assertJsonPath('code', 'ALREADY_APPROVED');

        // Segunda, de outra pessoa, com reservas: decide, com a nota de cada um.
        $this->actingAs($safety)->postJson("{$base}/{$id}/actions/approve_with_reservations", ['reason' => 'Rever rodapés no piso 2'])->assertOk()
            ->assertJsonPath('data.status', 'approved_with_reservations')
            ->assertJsonPath('data.decided_by', $safety->name)
            ->assertJsonPath('data.decision_note', "{$safety->name}: Rever rodapés no piso 2")
            ->assertJsonPath('data.events.5.label', 'Aprovada com reservas (2 de 2)')
            ->assertJsonPath('data.chain.valid', true);
    }

    public function test_return_restarts_the_approval_round_and_rejection_decides_at_once(): void
    {
        [, $project, $foreman, $inspector, $safety] = $this->setUpProject();
        $base = "/api/v1/projects/{$project}/inspections";
        $id = $this->submitted($foreman, $inspector, $base, ['approvals_required' => 2]);

        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/approve")->assertOk();
        $this->actingAs($safety)->postJson("{$base}/{$id}/actions/return", ['reason' => 'Falta fotografia'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/submit")->assertOk()->assertJsonPath('data.approvals.given', []);
        // A aprovação anterior já não conta: o fiscal pode voltar a aprovar.
        $this->postJson("{$base}/{$id}/actions/approve")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($safety)->postJson("{$base}/{$id}/actions/reject", ['reason' => 'Andaime sem ancoragem'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Andaime sem ancoragem');
    }

    public function test_report_pdf_is_generated_for_viewers_only(): void
    {
        [$owner, $project, $foreman, $inspector] = $this->setUpProject();
        $base = "/api/v1/projects/{$project}/inspections";
        $id = $this->submitted($foreman, $inspector, $base, ['hold_point' => true]);
        $item = $this->actingAs($inspector)->getJson("{$base}/{$id}")->json('data.items.0.id');
        $this->postJson("{$base}/{$id}/actions/return", ['reason' => 'Juntar fotografia'])->assertOk();
        $this->post("{$base}/{$id}/items/{$item}/photos", ['photo' => UploadedFile::fake()->image('guarda.jpg', 200, 150)], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();
        $this->postJson("{$base}/{$id}/actions/approve")->assertOk();

        $response = $this->get("{$base}/{$id}/report")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('inspecao-', $response->headers->get('Content-Disposition'));
        $this->assertGreaterThan(5000, strlen($response->getContent()));

        // Rascunho também sai (marcado como provisório); quem não vê inspeções não o obtém.
        $draft = $this->actingAs($owner)->postJson($base, ['items' => [['text' => 'y']]])->json('data.id');
        $this->get("{$base}/{$draft}/report")->assertOk();
        $worker = $this->addMember(app(TenantContext::class)->runWithoutTenant(fn () => Tenant::query()->first()), 'trabalhador');
        $this->actingAs($worker)->get("{$base}/{$id}/report")->assertForbidden();
    }

    public function test_team_members_are_notified_by_email_but_not_the_actor(): void
    {
        Mail::fake();
        [$owner, $project, $foreman, $inspector, $safety] = $this->setUpProject();
        $base = "/api/v1/projects/{$project}/inspections";
        $to = fn (User $u) => fn (ProjectNoticeMail $m) => $m->hasTo($u->email);

        // Pedido: avisa quem faz inspeções na equipa (fiscal e coordenador), não o encarregado que pediu.
        $id = $this->actingAs($foreman)->postJson($base, ['title' => 'Guarda-corpos [urgente](http://x)', 'items' => [['text' => 'x']]])->json('data.id');
        $this->postJson("{$base}/{$id}/actions/request")->assertOk();
        Mail::assertQueued(ProjectNoticeMail::class, $to($inspector));
        Mail::assertQueued(ProjectNoticeMail::class, $to($safety));
        Mail::assertNotQueued(ProjectNoticeMail::class, $to($foreman));
        // O texto dos utilizadores não vira ligação no email.
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => str_contains(implode(' ', $m->lines), '\\[urgente\\]\\(http://x\\)'));

        // Submissão: avisa quem decide; decisão: avisa quem pediu.
        $item = $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/start")->json('data.items.0.id');
        $this->patchJson("{$base}/{$id}/items/{$item}", ['result' => 'ok']);
        Mail::fake();
        $this->postJson("{$base}/{$id}/actions/submit")->assertOk();
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($safety->email) && str_contains($m->subjectLine, 'para decidir'));
        Mail::assertNotQueued(ProjectNoticeMail::class, $to($inspector));
        Mail::fake();
        $this->actingAs($safety)->postJson("{$base}/{$id}/actions/approve")->assertOk();
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($foreman->email) && str_contains($m->subjectLine, 'aprovada'));

        // Ocorrência atribuída: avisa o responsável; resolvida: avisa quem a registou.
        Mail::fake();
        $issue = $this->actingAs($inspector)->postJson("/api/v1/projects/{$project}/issues", ['title' => 'Rodapé em falta', 'assignee_user_id' => $foreman->id])->json('data.id');
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($foreman->email) && str_contains($m->subjectLine, 'responsável'));
        Mail::fake();
        $this->actingAs($foreman)->postJson("/api/v1/projects/{$project}/issues/{$issue}/actions/resolve", ['reason' => 'Colocado.'])->assertOk();
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($inspector->email) && str_contains($m->subjectLine, 'falta validar'));
        Mail::assertNotQueued(ProjectNoticeMail::class, $to($foreman));
        Mail::fake();
        $this->actingAs($inspector)->postJson("/api/v1/projects/{$project}/issues/{$issue}/actions/reopen", ['reason' => 'Mal fixado'])->assertOk();
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($foreman->email) && str_contains($m->subjectLine, 'reaberta'));

        // A reabertura só avisa o responsável.
        Mail::assertQueued(ProjectNoticeMail::class, 1);
        Mail::assertNotQueued(ProjectNoticeMail::class, $to($owner));
    }
}
