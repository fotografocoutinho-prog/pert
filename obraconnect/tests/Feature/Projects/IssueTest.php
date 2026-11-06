<?php

namespace Tests\Feature\Projects;

use App\Mail\IssuesOverdueMail;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IssueTest extends TestCase
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

    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $this->membershipId($tenant, $user)])->assertCreated();

        return $user;
    }

    public function test_issue_lifecycle_with_assignee_validation_and_history(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $sub = $this->teamMember($owner, $tenant, $project, 'subempreiteiro');
        $inspector = $this->teamMember($owner, $tenant, $project, 'fiscal');
        $base = "/api/v1/projects/{$project}/issues";

        $assignees = collect($this->actingAs($inspector)->getJson("{$base}/assignees")->assertOk()->json('data'));
        $this->assertTrue($assignees->contains('user_id', $sub->id));

        // O fiscal regista uma NC, com responsável (o subempreiteiro) e prazo.
        $id = $this->postJson($base, [
            'kind' => 'nonconformity',
            'severity' => 'major',
            'title' => 'Fissura na parede da cozinha',
            'assignee_user_id' => $sub->id,
            'due_on' => now('Europe/Lisbon')->addDays(3)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.reference', 'NC 1')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assignee', $sub->name)
            ->assertJsonPath('data.can.edit', true)
            ->json('data.id');
        $this->postJson($base, ['title' => 'x', 'due_on' => now('Europe/Lisbon')->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->postJson($base, ['title' => 'x', 'assignee_user_id' => $owner->id + 999])->assertUnprocessable()->assertJsonValidationErrors('assignee_user_id');
        $this->postJson($base, [])->assertUnprocessable()->assertJsonValidationErrors('title');

        // Só o responsável (ou quem valida) a resolve; o encarregado não.
        $this->actingAs($foreman)->postJson("{$base}/{$id}/actions/start")->assertForbidden()->assertJsonPath('code', 'NOT_ASSIGNEE');
        $this->actingAs($sub)->getJson("{$base}/{$id}")->assertJsonPath('data.can.actions', fn (array $a) => collect($a)->pluck('key')->all() === ['start', 'resolve']);
        $this->postJson("{$base}/{$id}/actions/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("{$base}/{$id}/comments", ['body' => 'Material encomendado.'])->assertOk();
        $this->post("{$base}/{$id}/photos", ['photo' => UploadedFile::fake()->image('fissura.jpg', 120, 90)], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("{$base}/{$id}/actions/resolve")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("{$base}/{$id}/actions/resolve", ['reason' => 'Fissura aberta e selada com argamassa de reparação.'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolved_by', $sub->name);
        $this->postJson("{$base}/{$id}/actions/close")->assertForbidden();

        // O fiscal reabre (com motivo) e depois valida.
        $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/reopen", ['reason' => 'Ainda se vê a fissura.'])->assertOk()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolution_note', null);
        $this->actingAs($sub)->postJson("{$base}/{$id}/actions/resolve", ['reason' => 'Refeito e pintado.'])->assertOk();
        $issue = $this->actingAs($inspector)->postJson("{$base}/{$id}/actions/close")->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_by', $inspector->name)
            ->assertJsonPath('data.can.edit', false)->json('data');
        $this->assertSame(['created', 'start', 'comment', 'resolve', 'reopen', 'resolve', 'close'], array_column($issue['events'], 'type'));
        $this->assertCount(1, $issue['photos']);
        // Fechada: já não se juntam fotografias nem se altera; comentários continuam.
        $this->actingAs($sub)->post("{$base}/{$id}/photos", ['photo' => UploadedFile::fake()->image('x.jpg', 10, 10)], ['Accept' => 'application/json'])->assertConflict();
        $this->actingAs($inspector)->putJson("{$base}/{$id}", ['title' => 'Outro'])->assertConflict();
        $this->postJson("{$base}/{$id}/comments", ['body' => 'Verificado na visita de dia 10.'])->assertOk();

        $this->getJson("{$base}?group=closed")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.photos_count', 1);
        $this->getJson("{$base}?group=open")->assertJsonPath('meta.total', 0);
        $this->actingAs($sub)->getJson("{$base}?mine=1")->assertJsonPath('meta.total', 1);
    }

    public function test_edit_rules_cancel_and_permissions(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $worker = $this->teamMember($owner, $tenant, $project, 'trabalhador');
        $base = "/api/v1/projects/{$project}/issues";

        // O trabalhador regista uma ocorrência (sem responsável) e pode alterá-la; o encarregado não (não a criou nem valida).
        $id = $this->actingAs($worker)->postJson($base, ['title' => 'Andaime sem guarda-corpos'])->assertCreated()
            ->assertJsonPath('data.reference', 'OC 1')->assertJsonPath('data.severity', 'minor')->json('data.id');
        $this->putJson("{$base}/{$id}", ['severity' => 'critical'])->assertOk()->assertJsonPath('data.severity_label', 'Crítica');
        $this->actingAs($foreman)->putJson("{$base}/{$id}", ['title' => 'Mudança'])->assertForbidden();
        // Sem responsável, quem pode resolver resolve.
        $this->postJson("{$base}/{$id}/actions/resolve", ['reason' => 'Guarda-corpos montados.'])->assertOk();
        $this->postJson("{$base}/{$id}/actions/cancel", ['reason' => 'x'])->assertForbidden();

        // O dono valida; mudar o prazo fica no historial e volta a permitir escalonar.
        $other = $this->actingAs($owner)->postJson($base, ['title' => 'Entulho na via pública', 'due_on' => now('Europe/Lisbon')->addDay()->toDateString()])->json('data.id');
        DB::table('issues')->where('id', $other)->update(['escalated_at' => now()]);
        $events = $this->putJson("{$base}/{$other}", ['due_on' => now('Europe/Lisbon')->addDays(5)->toDateString(), 'assignee_user_id' => $foreman->id])->assertOk()
            ->assertJsonPath('data.escalated_at', null)->json('data.events');
        $this->assertSame(['created', 'assigned', 'due_changed'], array_column($events, 'type'));
        $this->postJson("{$base}/{$other}/actions/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("{$base}/{$other}/actions/cancel", ['reason' => 'Registada em duplicado'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("{$base}/{$other}/actions/close")->assertConflict()->assertJsonPath('code', 'ISSUE_STATUS');

        // Medidor-orçamentista (vê a obra, sem permissões de ocorrências) não as vê; outra empresa não chega lá.
        $this->actingAs($this->addMember($tenant, 'medidor_orcamentista'))->getJson($base)->assertForbidden();
        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("{$base}/{$id}")->assertNotFound();

        // Obra arquivada: nada muda.
        $this->actingAs($owner)->putJson("/api/v1/projects/{$project}", ['status' => 'archived'])->assertOk();
        $this->postJson($base, ['title' => 'x'])->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
        $this->getJson("{$base}/{$id}")->assertOk()->assertJsonPath('data.can.actions', [])->assertJsonPath('data.can.comment', false);
    }

    public function test_nonconformity_raised_from_an_inspection_point(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $specialty = $this->getJson('/api/v1/specialties')->json('data.1.id');
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $building = $this->postJson("/api/v1/projects/{$project}/locations", ['kind' => 'building', 'name' => 'Bloco A'])->json('data.id');
        $base = "/api/v1/projects/{$project}/inspections";
        $id = $this->postJson($base, ['location_id' => $building, 'specialty_id' => $specialty, 'items' => [['text' => 'Prumo das paredes'], ['text' => 'Juntas da alvenaria']]])->json('data.id');
        $this->postJson("{$base}/{$id}/actions/request");
        $items = $this->postJson("{$base}/{$id}/actions/start")->json('data.items');
        $this->patchJson("{$base}/{$id}/items/{$items[0]['id']}", ['result' => 'ok']);
        $this->patchJson("{$base}/{$id}/items/{$items[1]['id']}", ['result' => 'nok', 'notes' => 'Juntas por preencher no alçado norte']);

        // Ponto conforme não dá NC; o não conforme dá, com o texto, a nota, o local e a especialidade da inspeção.
        $issues = "/api/v1/projects/{$project}/issues";
        $this->postJson($issues, ['inspection_item_id' => $items[0]['id']])->assertUnprocessable()->assertJsonValidationErrors('inspection_item_id');
        $this->postJson($issues, ['inspection_item_id' => $items[1]['id']])->assertCreated()
            ->assertJsonPath('data.reference', 'NC 1')
            ->assertJsonPath('data.title', 'Juntas da alvenaria')
            ->assertJsonPath('data.description', 'Juntas por preencher no alçado norte')
            ->assertJsonPath('data.location_id', $building)
            ->assertJsonPath('data.specialty_id', $specialty)
            ->assertJsonPath('data.inspection.number', 1)
            ->assertJsonPath('data.inspection_item_number', 2);
        $this->getJson("{$base}/{$id}")->assertJsonPath('data.items.1.issues.0.number', 1)->assertJsonPath('data.can.raise_issue', true);

        // Um ponto de outra obra não serve.
        $other = $this->postJson('/api/v1/projects', ['name' => 'Outra'])->json('data.id');
        $this->postJson("/api/v1/projects/{$other}/issues", ['inspection_item_id' => $items[1]['id']])->assertUnprocessable();

        // O local com ocorrências não se apaga.
        $this->deleteJson("/api/v1/projects/{$project}/locations/{$building}")->assertConflict();
    }

    public function test_overdue_issues_are_escalated_once_to_assignee_and_site_director(): void
    {
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $director = $this->teamMember($owner, $tenant, $project, 'diretor_obra');
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $base = "/api/v1/projects/{$project}/issues";
        $tomorrow = now('Europe/Lisbon')->addDay()->toDateString();

        $late = $this->actingAs($director)->postJson($base, ['title' => 'Vala sem sinalização', 'assignee_user_id' => $foreman->id, 'due_on' => $tomorrow])->json('data.id');
        $alsoLate = $this->postJson($base, ['title' => 'Contentor cheio', 'assignee_user_id' => $foreman->id, 'due_on' => $tomorrow])->json('data.id');
        $resolved = $this->postJson($base, ['title' => 'Já resolvida', 'due_on' => $tomorrow])->json('data.id');
        $this->postJson("{$base}/{$resolved}/actions/resolve", ['reason' => 'Feito.'])->assertOk();
        $onTime = $this->postJson($base, ['title' => 'Ainda no prazo', 'due_on' => now('Europe/Lisbon')->addDays(10)->toDateString()])->json('data.id');

        // Dois dias depois.
        $this->travel(2)->days();
        Artisan::call('obraconnect:escalate-issues');

        // O encarregado (responsável) recebe as duas num só email; o diretor de obra também.
        Mail::assertQueued(IssuesOverdueMail::class, 2);
        Mail::assertQueued(IssuesOverdueMail::class, fn (IssuesOverdueMail $m) => $m->hasTo($foreman->email) && count($m->issues) === 2 && $m->issues[0]['mine']);
        Mail::assertQueued(IssuesOverdueMail::class, fn (IssuesOverdueMail $m) => $m->hasTo($director->email) && ! $m->issues[0]['mine']);
        $this->getJson("{$base}/{$late}")->assertJsonPath('data.overdue', true)->assertJsonPath('data.events.1.type', 'escalated')
            ->assertJsonPath('data.events.1.payload.notified', fn (array $names) => count($names) === 2);
        $this->getJson("{$base}?group=overdue")->assertJsonPath('meta.total', 2);
        $this->getJson("{$base}/{$onTime}")->assertJsonPath('data.escalated_at', null);
        $this->getJson("{$base}/{$resolved}")->assertJsonPath('data.escalated_at', null);

        // Não volta a escalonar a mesma; sem diretor de obra na equipa, vai para os administradores.
        Artisan::call('obraconnect:escalate-issues');
        Mail::assertQueued(IssuesOverdueMail::class, 2);

        $project2 = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Sem diretor'])->json('data.id');
        $this->postJson("/api/v1/projects/{$project2}/issues", ['title' => 'Sem responsável', 'due_on' => now('Europe/Lisbon')->addDay()->toDateString()])->assertCreated();
        $this->travel(2)->days();
        Artisan::call('obraconnect:escalate-issues');
        Mail::assertQueued(IssuesOverdueMail::class, fn (IssuesOverdueMail $m) => $m->hasTo($owner->email) && $m->issues[0]['assignee'] === null);
        $this->assertSame(3, app(TenantContext::class)->runAs($tenant, fn () => Issue::query()->whereNotNull('escalated_at')->count()));
        $this->assertNull(app(TenantContext::class)->runAs($tenant, fn () => Issue::query()->find($alsoLate)->resolved_at));
    }
}
