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

class StructureTest extends TestCase
{
    /** @return array{0: User, 1: Tenant, 2: int} */
    private function project(): array
    {
        [$owner, $tenant] = $this->createCompany();

        return [$owner, $tenant, $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id')];
    }

    private function location(User $user, int $project, string $kind, string $name, ?int $parent = null)
    {
        return $this->actingAs($user)->postJson("/api/v1/projects/{$project}/locations", array_filter(['kind' => $kind, 'name' => $name, 'parent_id' => $parent]));
    }

    private function element(User $user, int $project, int $location, string $type, array $extra = [])
    {
        return $this->actingAs($user)->postJson("/api/v1/projects/{$project}/elements", $extra + ['location_id' => $location, 'element_type' => $type]);
    }

    public function test_building_floor_zone_hierarchy_rules(): void
    {
        [$owner, , $project] = $this->project();

        $building = $this->location($owner, $project, 'building', 'Bloco A')->assertCreated()->json('data.id');
        $floor = $this->location($owner, $project, 'floor', 'Piso 0', $building)->assertCreated()->json('data.id');
        $this->location($owner, $project, 'zone', 'Cozinha', $floor)->assertCreated();
        $this->location($owner, $project, 'zone', 'Logradouro', $building)->assertCreated();

        // Piso tem de estar num edifício; edifício só na raiz; zona não fica na raiz.
        $this->location($owner, $project, 'floor', 'Piso solto')->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->location($owner, $project, 'building', 'Bloco B', $building)->assertUnprocessable();
        $this->location($owner, $project, 'zone', 'Zona solta')->assertUnprocessable();
        $this->location($owner, $project, 'floor', 'Piso 1', $floor)->assertUnprocessable();

        $structure = $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/structure")->assertOk()->json('data');
        $this->assertCount(4, $structure['locations']);
        $this->assertSame(['Bloco A', 'Piso 0'], [$structure['locations'][0]['name'], $structure['locations'][1]['name']]);
        $this->assertSame('Pilar', collect($structure['element_types'])->firstWhere('value', 'column')['label']);
    }

    public function test_element_codes_are_automatic_and_unique_per_project(): void
    {
        [$owner, , $project] = $this->project();
        $building = $this->location($owner, $project, 'building', 'Bloco A')->json('data.id');
        $floor = $this->location($owner, $project, 'floor', 'Piso 0', $building)->json('data.id');

        $this->element($owner, $project, $floor, 'column')->assertCreated()->assertJsonPath('data.code', 'P1');
        $this->element($owner, $project, $floor, 'column')->assertJsonPath('data.code', 'P2');
        // PA (parede) não baralha a numeração de P (pilar).
        $this->element($owner, $project, $floor, 'wall')->assertJsonPath('data.code', 'PA1');
        $this->element($owner, $project, $floor, 'column', ['code' => 'P10'])->assertCreated();
        $this->element($owner, $project, $floor, 'column')->assertJsonPath('data.code', 'P11');
        $this->element($owner, $project, $floor, 'beam', ['code' => 'P1'])->assertUnprocessable()->assertJsonValidationErrors('code');

        // Outra obra começa do zero.
        $other = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Outra'])->json('data.id');
        $b2 = $this->location($owner, $other, 'building', 'Único')->json('data.id');
        $this->element($owner, $other, $b2, 'column')->assertJsonPath('data.code', 'P1');
        // Localização de outra obra não serve.
        $this->element($owner, $other, $floor, 'column')->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_only_empty_locations_can_be_deleted_and_elements_can_move(): void
    {
        [$owner, , $project] = $this->project();
        $building = $this->location($owner, $project, 'building', 'Bloco A')->json('data.id');
        $p0 = $this->location($owner, $project, 'floor', 'Piso 0', $building)->json('data.id');
        $p1 = $this->location($owner, $project, 'floor', 'Piso 1', $building)->json('data.id');
        $slab = $this->element($owner, $project, $p0, 'slab', ['name' => 'Laje do piso 0'])->json('data.id');

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/locations/{$p0}")->assertConflict()->assertJsonPath('code', 'LOCATION_NOT_EMPTY');
        $this->actingAs($owner)->patchJson("/api/v1/projects/{$project}/elements/{$slab}", ['location_id' => $p1])->assertOk()->assertJsonPath('data.location_id', $p1);
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/locations/{$p0}")->assertNoContent();
        $this->actingAs($owner)->patchJson("/api/v1/projects/{$project}/locations/{$p1}", ['name' => 'Piso 1 (cobertura)'])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/elements/{$slab}")->assertNoContent();
    }

    public function test_permissions_isolation_and_archived_project(): void
    {
        [$owner, $tenant, $project] = $this->project();
        $building = $this->location($owner, $project, 'building', 'Bloco A')->json('data.id');

        $join = function (string $role) use ($owner, $tenant, $project): User {
            $user = $this->addMember($tenant, $role);
            $id = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $user->id)->value('id'));
            $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $id])->assertCreated();

            return $user;
        };
        $director = $join('diretor_obra');
        $foreman = $join('encarregado');

        $this->location($director, $project, 'floor', 'Piso 0', $building)->assertCreated();
        $this->location($foreman, $project, 'floor', 'Piso 1', $building)->assertForbidden();
        $this->actingAs($foreman)->getJson("/api/v1/projects/{$project}/structure")->assertOk();
        $this->actingAs($this->addMember($tenant, 'encarregado'))->getJson("/api/v1/projects/{$project}/structure")->assertForbidden();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("/api/v1/projects/{$project}/structure")->assertNotFound();

        $this->actingAs($owner)->putJson("/api/v1/projects/{$project}", ['status' => 'archived'])->assertOk();
        $this->location($owner, $project, 'building', 'Bloco B')->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
    }

    public function test_structure_is_in_the_data_export(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant, $project] = $this->project();
        $building = $this->location($owner, $project, 'building', 'Bloco A')->json('data.id');
        $this->element($owner, $project, $building, 'footing')->assertCreated();

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $structure = json_decode($zip->getFromName('structure.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame('Bloco A', $structure[0]['locations'][0]['name']);
        $this->assertSame('S1', $structure[0]['elements'][0]['code']);
    }
}
