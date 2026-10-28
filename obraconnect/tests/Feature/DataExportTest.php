<?php

namespace Tests\Feature;

use App\Mail\ExportReadyMail;
use App\Models\TenantExport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DataExportTest extends TestCase
{
    public function test_export_contains_only_data_of_the_company(): void
    {
        Storage::fake('private');
        Mail::fake();

        [$ownerA, $a] = $this->createCompany('Empresa A');
        [$ownerB] = $this->createCompany('Empresa B');

        $exportId = $this->actingAs($ownerA)->postJson('/api/v1/exports')->assertCreated()->json('data.id');

        $export = app(TenantContext::class)->runAs($a, fn () => TenantExport::query()->findOrFail($exportId));
        $this->assertSame('completed', $export->status->value);
        Storage::disk('private')->assertExists($export->path);
        $this->assertStringStartsWith("tenants/{$a->id}/exports/", $export->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($export->path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));

        $members = json_decode($zip->getFromName('members.json'), true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        unlink($local);

        $emails = array_column($members, 'email');
        $this->assertContains($ownerA->email, $emails);
        $this->assertNotContains($ownerB->email, $emails);
        $this->assertSame($a->id, $manifest['tenant_id']);

        Mail::assertSent(ExportReadyMail::class, fn ($m) => $m->hasTo($ownerA->email));

        $this->actingAs($ownerA)->get("/api/v1/exports/{$exportId}/download")->assertOk();
        $this->actingAs($ownerB)->getJson("/api/v1/exports/{$exportId}/download")->assertNotFound();
    }

    public function test_expired_exports_are_pruned(): void
    {
        Storage::fake('private');
        Mail::fake();
        [$owner, $tenant] = $this->createCompany();

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $this->travel(config('obraconnect.exports.retention_hours') + 1)->hours();
        $this->artisan('obraconnect:prune-exports')->assertSuccessful();

        Storage::disk('private')->assertMissing($path);
        $this->actingAs($owner)->getJson("/api/v1/exports/{$exportId}/download")->assertNotFound();
    }
}
