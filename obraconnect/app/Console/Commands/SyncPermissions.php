<?php

namespace App\Console\Commands;

use App\Access\PermissionSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('obraconnect:sync-permissions')]
#[Description('Sincroniza a tabela de permissões com o catálogo em código')]
class SyncPermissions extends Command
{
    public function handle(PermissionSynchronizer $sync): int
    {
        $result = $sync->sync();

        $this->info('Permissões sincronizadas.');
        foreach ($result['created'] as $key) {
            $this->line("  + {$key}");
        }
        foreach ($result['removed'] as $key) {
            $this->warn("  ! {$key} já não existe no catálogo (mantida na BD sem efeito)");
        }

        return self::SUCCESS;
    }
}
