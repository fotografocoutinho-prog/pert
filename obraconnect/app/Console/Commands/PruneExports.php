<?php

namespace App\Console\Commands;

use App\Enums\ExportStatus;
use App\Models\TenantExport;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('obraconnect:prune-exports')]
#[Description('Apaga ficheiros de exportação expirados')]
class PruneExports extends Command
{
    public function handle(TenantContext $tenants): int
    {
        $count = $tenants->runWithoutTenant(function (): int {
            $expired = TenantExport::query()
                ->where('status', ExportStatus::Completed->value)
                ->where('expires_at', '<', now())
                ->get();

            foreach ($expired as $export) {
                if ($export->path) {
                    Storage::disk($export->disk)->delete($export->path);
                }
                $export->forceFill(['status' => ExportStatus::Expired, 'path' => null])->save();
            }

            return $expired->count();
        });

        $this->info("Exportações expiradas removidas: {$count}");

        return self::SUCCESS;
    }
}
