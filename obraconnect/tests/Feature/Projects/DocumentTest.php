<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /** @return array{0: User, 1: Tenant, 2: int} */
    private function project(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $id = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');

        return [$owner, $tenant, $id];
    }

    private function member(User $owner, Tenant $tenant, int $project, string $roleKey): User
    {
        $user = $this->addMember($tenant, $roleKey);
        $membershipId = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membershipId])->assertCreated();

        return $user;
    }

    private function upload(User $user, int $project, UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($user)->post("/api/v1/projects/{$project}/documents", $extra + ['file' => $file], ['Accept' => 'application/json']);
    }

    private function newVersion(User $user, int $project, int $document, UploadedFile $file, ?string $note = null)
    {
        return $this->actingAs($user)->post(
            "/api/v1/projects/{$project}/documents/{$document}/versions",
            array_filter(['file' => $file, 'note' => $note]),
            ['Accept' => 'application/json'],
        );
    }

    public function test_versions_are_kept_intact_and_downloads_are_logged(): void
    {
        [$owner, $tenant, $project] = $this->project();
        $v1 = UploadedFile::fake()->create('Caderno de encargos.pdf', 120, 'application/pdf');
        $hash1 = hash_file('sha256', $v1->getRealPath());

        $doc = $this->upload($owner, $project, $v1)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Caderno de encargos')
            ->assertJsonPath('data.current_version.number', 1)
            ->assertJsonPath('data.current_version.sha256', $hash1)
            ->assertJsonPath('data.created_by_me', true)
            ->json('data');

        $v2 = UploadedFile::fake()->create('Caderno de encargos rev1.pdf', 140, 'application/pdf');
        $this->newVersion($owner, $project, $doc['id'], $v2, 'Revisão do capítulo 3')
            ->assertOk()
            ->assertJsonPath('data.versions_count', 2)
            ->assertJsonPath('data.current_version.number', 2)
            ->assertJsonPath('data.versions.0.note', 'Revisão do capítulo 3')
            ->assertJsonPath('data.versions.1.number', 1);

        // A versão 1 continua igual, byte a byte.
        $first = $this->actingAs($owner)->get($doc['current_version']['urls']['download'])->assertOk();
        $this->assertSame($hash1, hash('sha256', $first->streamedContent()));
        $this->assertStringContainsString('attachment', (string) $first->headers->get('Content-Disposition'));

        // PDF abre no browser; cada pedido fica registado.
        $open = $this->actingAs($owner)->get($doc['current_version']['urls']['open'])->assertOk();
        $this->assertStringContainsString('inline', (string) $open->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $open->headers->get('Content-Type'));
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents/{$doc['id']}")
            ->assertJsonPath('data.versions.1.downloads_count', 2)
            ->assertJsonPath('data.versions.0.downloads_count', 0);

        $this->assertSame(
            $v1->getSize() + $v2->getSize(),
            (int) $tenant->fresh()->storage_used_bytes,
        );
    }

    public function test_files_the_browser_could_run_are_never_opened_inline(): void
    {
        [$owner, , $project] = $this->project();

        $doc = $this->upload($owner, $project, UploadedFile::fake()->create('planta.dwg', 50, 'image/vnd.dwg'))->assertCreated()->json('data');
        $this->assertNull($doc['current_version']['urls']['open']);

        $response = $this->actingAs($owner)->get($doc['current_version']['urls']['download'])->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));

        foreach (['virus.exe', 'shell.php', 'pagina.html', 'imagem.svg'] as $name) {
            $this->upload($owner, $project, UploadedFile::fake()->create($name, 10))
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
    }

    public function test_folders_navigation_moving_and_rules(): void
    {
        [$owner, , $project] = $this->project();

        $plans = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/folders", ['name' => 'Projeto'])->assertCreated()->json('data.id');
        $structure = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/folders", ['name' => 'Estabilidade', 'parent_id' => $plans])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/folders", ['name' => 'projeto'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $doc = $this->upload($owner, $project, UploadedFile::fake()->create('lajes.pdf', 10, 'application/pdf'), ['folder_id' => $structure])->json('data.id');

        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents?folder_id={$structure}")
            ->assertOk()
            ->assertJsonPath('data.path.0.name', 'Projeto')
            ->assertJsonPath('data.path.1.name', 'Estabilidade')
            ->assertJsonPath('data.documents.0.id', $doc);
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents?tree=1")
            ->assertJsonPath('data.folders.0.name', 'Projeto')
            ->assertJsonPath('data.folders.0.items_count', 1)
            ->assertJsonCount(0, 'data.documents')
            ->assertJsonCount(2, 'data.tree');

        // Pesquisa em todas as pastas, também pelo nome do ficheiro.
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents?search=lajes")
            ->assertJsonPath('data.documents.0.id', $doc)
            ->assertJsonCount(0, 'data.folders');

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/folders/{$plans}")->assertConflict()->assertJsonPath('code', 'FOLDER_NOT_EMPTY');
        $this->actingAs($owner)->patchJson("/api/v1/projects/{$project}/documents/{$doc}", ['folder_id' => $plans])->assertOk()->assertJsonPath('data.folder_id', $plans);
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/folders/{$structure}")->assertNoContent();
        $this->actingAs($owner)->patchJson("/api/v1/projects/{$project}/folders/{$plans}", ['name' => 'Projeto de execução'])->assertOk();
    }

    public function test_permissions_by_role_in_the_project(): void
    {
        [$owner, $tenant, $project] = $this->project();
        $foreman = $this->member($owner, $tenant, $project, 'encarregado');
        $worker = $this->member($owner, $tenant, $project, 'trabalhador');
        $outsider = $this->addMember($tenant, 'encarregado');

        $ownDoc = $this->upload($foreman, $project, UploadedFile::fake()->create('pedido.pdf', 10, 'application/pdf'))->assertCreated()->json('data.id');
        $ownerDoc = $this->upload($owner, $project, UploadedFile::fake()->create('mapa.pdf', 10, 'application/pdf'))->json('data');

        // Encarregado: envia e junta versões aos seus, mas não aos dos outros, nem gere pastas ou apaga.
        $this->newVersion($foreman, $project, $ownDoc, UploadedFile::fake()->create('pedido-v2.pdf', 10, 'application/pdf'))->assertOk();
        $this->newVersion($foreman, $project, $ownerDoc['id'], UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
            ->assertForbidden()->assertJsonPath('code', 'DOCUMENT_NOT_OWNER');
        $this->actingAs($foreman)->postJson("/api/v1/projects/{$project}/folders", ['name' => 'X'])->assertForbidden();
        $this->actingAs($foreman)->deleteJson("/api/v1/projects/{$project}/documents/{$ownDoc}")->assertForbidden();

        // Trabalhador: só consulta.
        $this->actingAs($worker)->get($ownerDoc['current_version']['urls']['download'])->assertOk();
        $this->upload($worker, $project, UploadedFile::fake()->create('y.pdf', 10, 'application/pdf'))->assertForbidden();

        // Fora da equipa: nada; outra empresa nem sabe que a obra existe.
        $this->actingAs($outsider)->getJson("/api/v1/projects/{$project}/documents")->assertForbidden();
        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->get($ownerDoc['current_version']['urls']['download'], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_version_must_belong_to_the_document_in_the_url(): void
    {
        [$owner, , $project] = $this->project();
        $a = $this->upload($owner, $project, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->json('data');
        $b = $this->upload($owner, $project, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'))->json('data.id');

        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents/{$b}/versions/{$a['current_version']['id']}/download")->assertNotFound();
    }

    public function test_quota_archived_project_and_deleting_free_space(): void
    {
        [$owner, $tenant, $project] = $this->project();
        $doc = $this->upload($owner, $project, UploadedFile::fake()->create('a.pdf', 300, 'application/pdf'))->json('data.id');
        $this->newVersion($owner, $project, $doc, UploadedFile::fake()->create('a2.pdf', 300, 'application/pdf'))->assertOk();
        $this->assertGreaterThan(0, $tenant->fresh()->storage_used_bytes);

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/documents/{$doc}")->assertNoContent();
        $this->assertSame(0, (int) $tenant->fresh()->storage_used_bytes);
        Storage::disk('private')->assertDirectoryEmpty("tenants/{$tenant->id}/projects/{$project}/documents");

        app(TenantContext::class)->runAs($tenant, fn () => Subscription::query()->latest('id')->first()->forceFill(['custom_limits' => ['storage_mb' => 1]])->save());
        $tenant->forceFill(['storage_used_bytes' => 1048576])->save();
        $this->upload($owner, $project, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'))->assertStatus(402);
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/documents")->assertJsonCount(0, 'data.documents');

        $tenant->forceFill(['storage_used_bytes' => 0])->save();
        $this->actingAs($owner)->putJson("/api/v1/projects/{$project}", ['status' => 'archived'])->assertOk();
        $this->upload($owner, $project, UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'))->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
    }

    public function test_documents_are_in_the_data_export(): void
    {
        Mail::fake();
        [$owner, $tenant, $project] = $this->project();
        $hash = $this->upload($owner, $project, UploadedFile::fake()->create('mapa.pdf', 10, 'application/pdf'))->json('data.current_version.sha256');

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $documents = json_decode($zip->getFromName('documents.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('mapa', $documents[0]['title']);
        $this->assertSame($hash, $documents[0]['versions'][0]['sha256']);
    }
}
