<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DiaryTest extends TestCase
{
    /** @return array{0: User, 1: Tenant, 2: int} */
    private function companyWithProject(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $id = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');

        return [$owner, $tenant, $id];
    }

    private function joinTeam(User $owner, Tenant $tenant, int $projectId, User $user, ?string $roleKey = null): void
    {
        $membershipId = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $user->id)->value('id'));

        $this->actingAs($owner)->postJson("/api/v1/projects/{$projectId}/members", array_filter([
            'membership_id' => $membershipId,
            'role_id' => $roleKey ? $this->roleId($tenant, $roleKey) : null,
        ]))->assertCreated();
    }

    private function entry(array $overrides = []): array
    {
        return $overrides + [
            'entry_date' => now('Europe/Lisbon')->toDateString(),
            'weather' => ['morning' => 'chuva', 'afternoon' => 'nublado', 'temp_min' => 8, 'temp_max' => 15],
            'work_summary' => 'Betonagem da laje do piso 1.',
            'items' => [
                ['kind' => 'workforce', 'description' => 'Pedreiros', 'quantity' => 4, 'company' => 'Construções Silva'],
                ['kind' => 'workforce', 'description' => 'Serventes', 'quantity' => 2],
                ['kind' => 'material', 'description' => 'Betão C25/30', 'quantity' => 12.5, 'unit' => 'm3'],
                ['kind' => 'visit', 'description' => 'Fiscalização', 'company' => 'Câmara Municipal'],
            ],
        ];
    }

    public function test_foreman_writes_and_submits_a_daily_entry(): void
    {
        [$owner, $tenant, $id] = $this->companyWithProject();
        $foreman = $this->addMember($tenant, 'encarregado');
        $this->joinTeam($owner, $tenant, $id, $foreman);

        $entryId = $this->actingAs($foreman)->postJson("/api/v1/projects/{$id}/diary", $this->entry())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.weather.morning', 'chuva')
            ->assertJsonPath('data.items.2.quantity', 12.5)
            ->assertJsonPath('data.items.2.kind_label', 'Materiais recebidos')
            ->assertJsonPath('data.created_by', $foreman->name)
            ->json('data.id');

        // Rascunho: pode corrigir. As linhas são substituídas pelo que vier no pedido.
        $this->actingAs($foreman)->putJson("/api/v1/projects/{$id}/diary/{$entryId}", [
            'notes' => 'Chuva parou às 11h.',
            'items' => [['kind' => 'workforce', 'description' => 'Pedreiros', 'quantity' => 5]],
        ])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.notes', 'Chuva parou às 11h.');

        $this->actingAs($foreman)->getJson("/api/v1/projects/{$id}/diary")
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.workforce_total', 5);

        $this->actingAs($foreman)->postJson("/api/v1/projects/{$id}/diary/{$entryId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submitted_by', $foreman->name);

        // Submetido: fechado para sempre.
        $this->actingAs($foreman)->putJson("/api/v1/projects/{$id}/diary/{$entryId}", ['notes' => 'x'])
            ->assertConflict()->assertJsonPath('code', 'DIARY_SUBMITTED');
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$id}/diary/{$entryId}")->assertConflict();
    }

    public function test_one_entry_per_day_and_no_future_days(): void
    {
        [$owner, , $id] = $this->companyWithProject();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())
            ->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry(['entry_date' => now('Europe/Lisbon')->addDays(2)->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry([
            'entry_date' => now('Europe/Lisbon')->subDay()->toDateString(),
            'weather' => ['temp_min' => 20, 'temp_max' => 10],
        ]))->assertUnprocessable()->assertJsonValidationErrors('weather.temp_max');
    }

    public function test_viewers_cannot_write_and_outsiders_cannot_read(): void
    {
        [$owner, $tenant, $id] = $this->companyWithProject();
        $inspector = $this->addMember($tenant, 'fiscal');
        $outsider = $this->addMember($tenant, 'encarregado');
        $this->joinTeam($owner, $tenant, $id, $inspector);
        $entryId = $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())->json('data.id');

        $this->actingAs($inspector)->getJson("/api/v1/projects/{$id}/diary/{$entryId}")->assertOk();
        $this->actingAs($inspector)->postJson("/api/v1/projects/{$id}/diary", $this->entry(['entry_date' => now()->subDays(3)->toDateString()]))->assertForbidden();
        $this->actingAs($inspector)->postJson("/api/v1/projects/{$id}/diary/{$entryId}/submit")->assertForbidden();

        // Encarregado que não está na equipa desta obra.
        $this->actingAs($outsider)->getJson("/api/v1/projects/{$id}/diary")->assertForbidden();
    }

    public function test_entry_must_belong_to_the_project_in_the_url(): void
    {
        [$owner, , $id] = $this->companyWithProject();
        $other = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Outra'])->json('data.id');
        $entryId = $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())->json('data.id');

        $this->actingAs($owner)->getJson("/api/v1/projects/{$other}/diary/{$entryId}")->assertNotFound();
    }

    public function test_date_cannot_change_and_month_filter_works(): void
    {
        [$owner, , $id] = $this->companyWithProject();
        $entryId = $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry(['entry_date' => '2026-01-31']))->assertCreated();

        $this->actingAs($owner)->putJson("/api/v1/projects/{$id}/diary/{$entryId}", ['entry_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->actingAs($owner)->getJson("/api/v1/projects/{$id}/diary?month=2026-01")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.entry_date', '2026-01-31');
    }

    public function test_projects_and_diary_are_included_in_the_data_export(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant, $id] = $this->companyWithProject();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/diary", $this->entry())->assertCreated();

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $projects = json_decode($zip->getFromName('projects.json'), true);
        $diary = json_decode($zip->getFromName('site_diary.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('Moradia', $projects[0]['name']);
        $this->assertSame($owner->email, $projects[0]['team'][0]['email']);
        $this->assertSame('Betonagem da laje do piso 1.', $diary[0]['work_summary']);
        $this->assertCount(4, $diary[0]['items']);
    }
}
