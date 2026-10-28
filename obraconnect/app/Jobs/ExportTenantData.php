<?php

namespace App\Jobs;

use App\Audit\Auditor;
use App\Enums\ExportStatus;
use App\Mail\ExportReadyMail;
use App\Models\TenantExport;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDataExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A empresa é restaurada a partir do Context do Laravel antes de este código correr
 * (ver AppServiceProvider::configureQueuedTenantContext). Recebe só o ID, para o
 * modelo ser carregado já com o contexto ativo.
 */
class ExportTenantData implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function handle(TenantContext $tenants, TenantDataExporter $exporter, Auditor $auditor): void
    {
        if (! $tenants->hasTenant()) {
            throw new MissingTenantContextException('ExportTenantData sem empresa no contexto.');
        }

        $export = TenantExport::query()->with('requestedBy')->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => ExportStatus::Processing])->save();

        $tenant = $tenants->tenantOrFail();
        $localPath = $exporter->exportToTemporaryFile();

        try {
            $target = "tenants/{$tenant->id}/exports/export-{$export->id}-".now()->format('Ymd-His').'.zip';
            $stream = fopen($localPath, 'rb');
            Storage::disk($export->disk)->writeStream($target, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => ExportStatus::Completed,
                'path' => $target,
                'size_bytes' => filesize($localPath),
                'checksum_sha256' => hash_file('sha256', $localPath),
                'completed_at' => now(),
                'expires_at' => now()->addHours(config('obraconnect.exports.retention_hours')),
            ])->save();
        } finally {
            @unlink($localPath);
        }

        $auditor->record('data.export_completed', $export, ['size' => $export->size_bytes], actorType: 'system');

        if ($export->requestedBy) {
            Mail::to($export->requestedBy->email)->send(new ExportReadyMail($tenant->name, $export->expires_at));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $tenants = app(TenantContext::class);

        if (! $tenants->hasTenant()) {
            return;
        }

        TenantExport::query()->whereKey($this->exportId)->update([
            'status' => ExportStatus::Failed->value,
            'error' => mb_substr((string) $exception?->getMessage(), 0, 1000),
        ]);
    }
}
