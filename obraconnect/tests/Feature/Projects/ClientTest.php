<?php

namespace Tests\Feature\Projects;

use App\Models\AuditLog;
use App\Models\TenantExport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ClientTest extends TestCase
{
    public function test_create_and_edit_with_nif_rules(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);

        $client = $this->postJson('/api/v1/clients', [
            'type' => 'company', 'name' => ' Construções Silva ', 'nif' => 'PT 500 000 000', 'postal_code' => '4000-123', 'email' => 'geral@silva.pt',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Construções Silva')
            ->assertJsonPath('data.nif', '500000000')
            ->assertJsonPath('data.type_label', 'Empresa')
            ->json('data.id');

        // NIF repetido, NIF com dígito de controlo errado, código postal português mal escrito.
        $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Outro', 'nif' => '500000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('nif');
        $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Outro', 'nif' => '123456780'])
            ->assertUnprocessable()->assertJsonValidationErrors('nif');
        $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Outro', 'postal_code' => '4000'])
            ->assertUnprocessable()->assertJsonValidationErrors('postal_code');
        $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Ana Costa', 'nif' => '123456789'])->assertCreated();
        // Cliente estrangeiro: NIF/IVA do país dele, sem a validação portuguesa.
        $this->postJson('/api/v1/clients', ['type' => 'company', 'name' => 'Obras Vigo SL', 'country_code' => 'es', 'nif' => 'b-12345678'])
            ->assertCreated()->assertJsonPath('data.nif', 'B12345678')->assertJsonPath('data.country_code', 'ES');

        $this->putJson("/api/v1/clients/{$client}", ['contact_name' => 'Eng. Rui Silva', 'nif' => null])
            ->assertOk()->assertJsonPath('data.contact_name', 'Eng. Rui Silva')->assertJsonPath('data.nif', null);
        $this->putJson("/api/v1/clients/{$client}", ['nif' => '500000000'])->assertOk();

        $this->getJson('/api/v1/clients?search=silva')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.name', 'Ana Costa');
        $this->assertTrue(AuditLog::query()->where('event', 'client.updated')->exists());
    }

    public function test_client_on_projects_archive_instead_of_delete(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $client = $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Ana Costa'])->json('data.id');
        $spare = $this->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Sem obras'])->json('data.id');

        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia Costa', 'client_id' => $client])
            ->assertCreated()->assertJsonPath('data.client.name', 'Ana Costa')->json('data.id');
        $this->postJson('/api/v1/projects', ['name' => 'Outra obra'])->assertCreated()->assertJsonPath('data.client', null);
        $this->getJson("/api/v1/projects?client_id={$client}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/clients/{$client}")->assertJsonPath('data.projects_count', 1);

        $this->deleteJson("/api/v1/clients/{$client}")->assertConflict()->assertJsonPath('code', 'CLIENT_IN_USE');
        $this->putJson("/api/v1/clients/{$client}", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);
        $this->assertTrue(AuditLog::query()->where('event', 'client.archived')->exists());

        // Arquivado: sai da lista e das opções, mas a obra que já o tinha continua a mostrá-lo.
        $this->getJson('/api/v1/clients')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/clients?status=archived')->assertJsonCount(1, 'data');
        $this->assertSame(['Sem obras'], collect($this->getJson('/api/v1/clients/options')->json('data'))->pluck('name')->all());
        $this->assertCount(2, $this->getJson("/api/v1/clients/options?include={$client}")->json('data'));
        $this->getJson("/api/v1/projects/{$project}")->assertJsonPath('data.client.name', 'Ana Costa');
        $this->putJson("/api/v1/projects/{$project}", ['name' => 'Moradia Costa (ampliação)', 'client_id' => $client])->assertOk();
        $this->postJson('/api/v1/projects', ['name' => 'Nova', 'client_id' => $client])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->putJson("/api/v1/clients/{$client}", ['archived' => false])->assertOk()->assertJsonPath('data.archived', false);
        $this->deleteJson("/api/v1/clients/{$spare}")->assertNoContent();
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $client = $this->actingAs($owner)->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Ana Costa'])->json('data.id');

        $foreman = $this->addMember($tenant, 'encarregado');
        $this->actingAs($foreman)->getJson('/api/v1/clients')->assertForbidden();
        $this->actingAs($foreman)->getJson('/api/v1/clients/options')->assertForbidden();

        $director = $this->addMember($tenant, 'diretor_obra');
        $this->actingAs($director)->getJson("/api/v1/clients/{$client}")->assertOk();
        $this->actingAs($director)->getJson('/api/v1/clients/options')->assertOk();
        $this->actingAs($director)->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'X'])->assertForbidden();
        $this->actingAs($director)->putJson("/api/v1/clients/{$client}", ['name' => 'X'])->assertForbidden();

        $clerk = $this->addMember($tenant, 'administrativo');
        $this->actingAs($clerk)->postJson('/api/v1/clients', ['type' => 'public', 'name' => 'Câmara Municipal'])->assertCreated();

        // Outra empresa não vê nem usa o cliente.
        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("/api/v1/clients/{$client}")->assertNotFound();
        $this->actingAs($ownerB)->postJson('/api/v1/projects', ['name' => 'Obra B', 'client_id' => $client])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_clients_and_calendars_are_in_the_data_export(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner)->postJson('/api/v1/clients', ['type' => 'private', 'name' => 'Ana Costa', 'nif' => '123456789'])->assertCreated();
        $this->getJson('/api/v1/work-calendars')->assertOk();

        $exportId = $this->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $clients = json_decode($zip->getFromName('clients.json'), true);
        $calendars = json_decode($zip->getFromName('work_calendars.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('123456789', $clients[0]['nif']);
        $this->assertSame('Calendário geral', $calendars[0]['name']);
        $this->assertCount(26, $calendars[0]['days_off']);
    }
}
