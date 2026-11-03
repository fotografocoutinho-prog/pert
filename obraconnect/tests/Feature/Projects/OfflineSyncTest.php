<?php

namespace Tests\Feature\Projects;

use App\Models\DiaryEntry;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Reenvios da fila do telemóvel (modo sem rede) não podem duplicar nada. */
class OfflineSyncTest extends TestCase
{
    /** @return array{0: User, 1: Tenant, 2: int} */
    private function project(): array
    {
        [$owner, $tenant] = $this->createCompany();

        return [$owner, $tenant, $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id')];
    }

    public function test_resending_an_offline_entry_returns_the_same_entry(): void
    {
        [$owner, $tenant, $project] = $this->project();
        $uuid = (string) Str::uuid();
        $payload = ['client_uuid' => $uuid, 'entry_date' => now('Europe/Lisbon')->toDateString(), 'work_summary' => 'Feito sem rede.'];

        $first = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", $payload)->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", $payload)
            ->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.work_summary', 'Feito sem rede.');

        $this->assertSame(1, app(TenantContext::class)->runAs($tenant, fn () => DiaryEntry::query()->count()));

        // Outro aparelho, mesmo dia: conflito (não substitui nada).
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['client_uuid' => (string) Str::uuid()] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['client_uuid' => 'nao-e-uuid'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_client_uuid_of_another_project_is_rejected(): void
    {
        [$owner, , $project] = $this->project();
        $other = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Outra'])->json('data.id');
        $uuid = (string) Str::uuid();
        $date = now('Europe/Lisbon')->toDateString();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['client_uuid' => $uuid, 'entry_date' => $date])->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$other}/diary", ['client_uuid' => $uuid, 'entry_date' => $date])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_resending_an_offline_photo_does_not_duplicate_it_nor_the_quota(): void
    {
        Storage::fake('private');
        [$owner, $tenant, $project] = $this->project();
        $entry = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['entry_date' => now('Europe/Lisbon')->toDateString()])->json('data.id');
        $uuid = (string) Str::uuid();
        $send = fn () => $this->actingAs($owner)->post("/api/v1/projects/{$project}/diary/{$entry}/photos", [
            'photo' => UploadedFile::fake()->image('sem-rede.jpg', 800, 600),
            'client_uuid' => $uuid,
        ], ['Accept' => 'application/json']);

        $id = $send()->assertCreated()->json('data.id');
        $used = (int) $tenant->fresh()->storage_used_bytes;

        $send()->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, app(TenantContext::class)->runAs($tenant, fn () => Media::query()->count()));
        $this->assertSame($used, (int) $tenant->fresh()->storage_used_bytes);
    }
}
