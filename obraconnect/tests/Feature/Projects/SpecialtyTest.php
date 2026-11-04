<?php

namespace Tests\Feature\Projects;

use App\Models\AuditLog;
use App\Models\Specialty;
use App\Models\TenantExport;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SpecialtyTest extends TestCase
{
    public function test_new_companies_get_the_usual_list_and_can_edit_it(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);

        $list = collect($this->getJson('/api/v1/specialties')->assertOk()->json('data'));
        $this->assertCount(count(Specialty::DEFAULTS), $list);
        $this->assertSame(['ARQ', 'EST'], $list->take(2)->pluck('code')->all());

        $this->postJson('/api/v1/specialties', ['code' => ' geo ', 'name' => 'Geotecnia'])->assertCreated()->assertJsonPath('data.code', 'GEO');
        $this->postJson('/api/v1/specialties', ['code' => 'avac', 'name' => 'Repetida'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/specialties', ['code' => 'A B', 'name' => 'Má'])->assertUnprocessable()->assertJsonValidationErrors('code');

        // Apagadas da lista habitual voltam com "juntar a lista habitual"; as que existem não se repetem.
        $gas = $list->firstWhere('code', 'GAS')['id'];
        $this->deleteJson("/api/v1/specialties/{$gas}")->assertNoContent();
        $this->postJson('/api/v1/specialties/defaults')->assertOk()->assertJsonPath('data.added', 1);
        $this->postJson('/api/v1/specialties/defaults')->assertJsonPath('data.added', 0);

        // Empresa anterior a esta versão (sem especialidades): junta a lista com o mesmo botão.
        app(TenantContext::class)->runAs($tenant, fn () => Specialty::query()->delete());
        $this->getJson('/api/v1/specialties')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/specialties/defaults')->assertJsonPath('data.added', count(Specialty::DEFAULTS));
        $this->assertTrue(AuditLog::query()->where('event', 'specialty.defaults_added')->exists());
    }

    public function test_elements_and_documents_have_a_specialty(): void
    {
        Storage::fake('private');
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $specialties = collect($this->getJson('/api/v1/specialties')->json('data'))->keyBy('code');
        [$est, $avac] = [$specialties['EST']['id'], $specialties['AVAC']['id']];

        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $building = $this->postJson("/api/v1/projects/{$project}/locations", ['kind' => 'building', 'name' => 'Bloco A'])->json('data.id');
        $column = $this->postJson("/api/v1/projects/{$project}/elements", ['location_id' => $building, 'element_type' => 'column', 'specialty_id' => $est])
            ->assertCreated()->assertJsonPath('data.specialty_id', $est)->json('data.id');
        $structure = $this->getJson("/api/v1/projects/{$project}/structure")->json('data');
        $this->assertSame('EST', collect($structure['specialties'])->firstWhere('id', $est)['code']);

        $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%test\n");
        $plan = $this->post("/api/v1/projects/{$project}/documents", ['file' => $pdf('planta-avac.pdf'), 'specialty_id' => $avac], $this->spaHeaders)
            ->assertCreated()->assertJsonPath('data.specialty_id', $avac)->json('data.id');
        $folder = $this->postJson("/api/v1/projects/{$project}/folders", ['name' => 'Projeto'])->json('data.id');
        $this->post("/api/v1/projects/{$project}/documents", ['file' => $pdf('pilares.pdf'), 'folder_id' => $folder, 'specialty_id' => $est], $this->spaHeaders)->assertCreated();

        // Filtro por especialidade: em todas as pastas.
        $this->getJson("/api/v1/projects/{$project}/documents?specialty_id={$est}")->assertOk()
            ->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.title', 'pilares')->assertJsonCount(0, 'data.folders');
        $this->getJson("/api/v1/projects/{$project}/documents")->assertJsonCount(1, 'data.documents');

        // Com ligações não se apaga; arquivada fica onde estava mas não se escolhe de novo.
        $this->deleteJson("/api/v1/specialties/{$est}")->assertConflict()->assertJsonPath('code', 'SPECIALTY_IN_USE');
        $this->putJson("/api/v1/specialties/{$est}", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.elements_count', 1)->assertJsonPath('data.documents_count', 1);
        $this->patchJson("/api/v1/projects/{$project}/elements/{$column}", ['name' => 'Pilar canto', 'specialty_id' => $est])->assertOk();
        $this->postJson("/api/v1/projects/{$project}/elements", ['location_id' => $building, 'element_type' => 'beam', 'specialty_id' => $est])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->patchJson("/api/v1/projects/{$project}/documents/{$plan}", ['specialty_id' => $est])->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->patchJson("/api/v1/projects/{$project}/documents/{$plan}", ['specialty_id' => null])->assertOk()->assertJsonPath('data.specialty_id', null);
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $specialty = $this->actingAs($owner)->getJson('/api/v1/specialties')->json('data.0.id');
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');

        $foreman = $this->addMember($tenant, 'encarregado');
        $this->actingAs($foreman)->getJson('/api/v1/specialties')->assertOk();
        $this->actingAs($foreman)->postJson('/api/v1/specialties', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->actingAs($foreman)->postJson('/api/v1/specialties/defaults')->assertForbidden();

        // Subempreiteiro (sem "ver empresa") vê a lista para classificar os documentos que envia.
        $sub = $this->addMember($tenant, 'subempreiteiro');
        $this->actingAs($sub)->getJson('/api/v1/specialties')->assertOk();

        $this->actingAs($this->addMember($tenant, 'diretor_tecnico'))->putJson("/api/v1/specialties/{$specialty}", ['name' => 'Arquitetura e interiores'])->assertOk();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->putJson("/api/v1/specialties/{$specialty}", ['name' => 'X'])->assertNotFound();
        $projectB = $this->postJson('/api/v1/projects', ['name' => 'Obra B'])->json('data.id');
        $buildingB = $this->postJson("/api/v1/projects/{$projectB}/locations", ['kind' => 'building', 'name' => 'B'])->json('data.id');
        $this->postJson("/api/v1/projects/{$projectB}/elements", ['location_id' => $buildingB, 'element_type' => 'column', 'specialty_id' => $specialty])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
    }

    public function test_specialties_are_in_the_data_export(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $specialties = json_decode($zip->getFromName('specialties.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('ARQ', $specialties[0]['code']);
        $this->assertCount(count(Specialty::DEFAULTS), $specialties);
    }
}
