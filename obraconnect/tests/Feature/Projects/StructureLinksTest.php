<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StructureLinksTest extends TestCase
{
    /** @var array{owner: User, tenant: Tenant, project: int, building: int, floor: int, zone: int, column: int, entry: int} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $post = fn (string $url, array $data) => $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/{$url}", $data)->assertCreated()->json('data.id');

        $building = $post('locations', ['kind' => 'building', 'name' => 'Bloco A']);
        $floor = $post('locations', ['kind' => 'floor', 'name' => 'Piso 0', 'parent_id' => $building]);
        $zone = $post('locations', ['kind' => 'zone', 'name' => 'Cozinha', 'parent_id' => $floor]);
        $column = $post('elements', ['location_id' => $floor, 'element_type' => 'column']);
        $entry = $post('diary', ['entry_date' => now('Europe/Lisbon')->toDateString()]);

        $this->s = compact('owner', 'tenant', 'project', 'building', 'floor', 'zone', 'column', 'entry');
    }

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->s['project']}/{$path}";
    }

    public function test_diary_lines_photos_and_documents_point_to_the_structure(): void
    {
        ['owner' => $owner, 'entry' => $entry, 'column' => $column, 'floor' => $floor, 'zone' => $zone] = $this->s;

        // Com elemento, o local vem dele (mesmo que se indique outro).
        $this->actingAs($owner)->putJson($this->url("diary/{$entry}"), ['items' => [
            ['kind' => 'material', 'description' => 'Betão C25/30', 'quantity' => 3, 'unit' => 'm3', 'element_id' => $column, 'location_id' => $zone],
            ['kind' => 'incident', 'description' => 'Fissura no pavimento', 'location_id' => $zone],
            ['kind' => 'workforce', 'description' => 'Pedreiros', 'quantity' => 2],
        ]])->assertOk()
            ->assertJsonPath('data.items.0.element_id', $column)
            ->assertJsonPath('data.items.0.location_id', $floor)
            ->assertJsonPath('data.items.1.location_id', $zone)
            ->assertJsonPath('data.items.2.location_id', null);

        $photo = $this->actingAs($owner)->post($this->url("diary/{$entry}/photos"), [
            'photo' => UploadedFile::fake()->image('p1.jpg', 640, 480),
            'element_id' => $column,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.location_id', $floor)->json('data.id');

        $doc = $this->actingAs($owner)->post($this->url('documents'), [
            'file' => UploadedFile::fake()->create('pormenor-cozinha.pdf', 10, 'application/pdf'),
            'location_id' => $zone,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.location_id', $zone)->json('data.id');

        // Histórico do pilar: a linha do betão e a fotografia.
        $links = $this->actingAs($owner)->getJson($this->url("elements/{$column}/links"))->assertOk()->json('data');
        $this->assertSame(['Betão C25/30'], array_column($links['diary_items'], 'description'));
        $this->assertSame([$photo], array_column($links['photos'], 'id'));
        $this->assertSame([], $links['documents']);

        // Histórico do piso: inclui o que está nas zonas e nos elementos dentro dele.
        $links = $this->actingAs($owner)->getJson($this->url("locations/{$floor}/links"))->json('data');
        $this->assertEqualsCanonicalizing(['Betão C25/30', 'Fissura no pavimento'], array_column($links['diary_items'], 'description'));
        $this->assertSame([$doc], array_column($links['documents'], 'id'));
        $this->assertCount(1, $links['photos']);
    }

    public function test_links_must_be_from_the_same_project(): void
    {
        ['owner' => $owner, 'entry' => $entry, 'column' => $column] = $this->s;
        $other = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Outra'])->json('data.id');
        $otherEntry = $this->actingAs($owner)->postJson("/api/v1/projects/{$other}/diary", ['entry_date' => now('Europe/Lisbon')->toDateString()])->json('data.id');

        $this->actingAs($owner)->putJson("/api/v1/projects/{$other}/diary/{$otherEntry}", ['items' => [
            ['kind' => 'incident', 'description' => 'X', 'element_id' => $column],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.element_id');

        $this->actingAs($owner)->post("/api/v1/projects/{$other}/documents", [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            'location_id' => $this->s['floor'],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->assertNotNull($entry);
    }

    public function test_linked_structure_cannot_be_deleted_and_links_follow_a_moved_element(): void
    {
        ['owner' => $owner, 'entry' => $entry, 'column' => $column, 'floor' => $floor, 'zone' => $zone, 'building' => $building] = $this->s;
        $this->actingAs($owner)->putJson($this->url("diary/{$entry}"), ['items' => [
            ['kind' => 'incident', 'description' => 'Pilar com chocho', 'element_id' => $column],
            ['kind' => 'visit', 'description' => 'Projetista', 'location_id' => $zone],
        ]])->assertOk();

        $this->actingAs($owner)->deleteJson($this->url("elements/{$column}"))->assertConflict()->assertJsonPath('code', 'ELEMENT_IN_USE');
        $this->actingAs($owner)->deleteJson($this->url("locations/{$zone}"))->assertConflict()->assertJsonPath('code', 'LOCATION_IN_USE');

        // O pilar passa para outro piso: a linha do diário acompanha-o.
        $p1 = $this->actingAs($owner)->postJson($this->url('locations'), ['kind' => 'floor', 'name' => 'Piso 1', 'parent_id' => $building])->json('data.id');
        $this->actingAs($owner)->patchJson($this->url("elements/{$column}"), ['location_id' => $p1])->assertOk();
        $this->actingAs($owner)->getJson($this->url("diary/{$entry}"))->assertJsonPath('data.items.0.location_id', $p1);
        $this->assertSame(['Projetista'], array_column($this->actingAs($owner)->getJson($this->url("locations/{$floor}/links"))->json('data.diary_items'), 'description'));
        $this->assertSame(['Pilar com chocho'], array_column($this->actingAs($owner)->getJson($this->url("locations/{$p1}/links"))->json('data.diary_items'), 'description'));
    }

    public function test_history_respects_diary_and_document_permissions(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'column' => $column] = $this->s;
        $worker = $this->addMember($tenant, 'trabalhador');
        $membershipId = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $worker->id)->value('id'));
        $this->actingAs($owner)->postJson($this->url('members'), ['membership_id' => $membershipId])->assertCreated();

        // O trabalhador vê a obra e os documentos, mas não o diário.
        $links = $this->actingAs($worker)->getJson($this->url("elements/{$column}/links"))->assertOk()->json('data');
        $this->assertNull($links['diary_items']);
        $this->assertNull($links['photos']);
        $this->assertSame([], $links['documents']);
    }
}
