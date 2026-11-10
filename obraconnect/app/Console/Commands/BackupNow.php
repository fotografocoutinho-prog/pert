<?php

namespace App\Console\Commands;

use App\Backup\Backups;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('obraconnect:backup {--no-files : Só a base de dados} {--path= : Pasta de destino (por omissão a de config/obraconnect.php)}')]
#[Description('Cópia de segurança da base de dados (.sql.gz) e dos ficheiros das empresas (.zip); guarda 14 dias')]
class BackupNow extends Command
{
    public function handle(Backups $backups, TenantContext $tenants): int
    {
        if ($this->option('path')) {
            config(['obraconnect.backup.path' => $this->option('path')]);
        }
        $result = $tenants->runWithoutTenant(fn () => $backups->run(! $this->option('no-files')));

        $mb = fn (int $b) => number_format($b / 1048576, 1, ',', ' ').' MB';
        $this->info("Base de dados: {$result['db']['file']} ({$result['db']['tables']} tabelas, {$result['db']['rows']} linhas, {$mb($result['db']['bytes'])})");
        $files = $result['files'];
        if ($files === null) {
            $this->line('Ficheiros: não incluídos');
        } elseif (isset($files['skipped'])) {
            $this->warn('Ficheiros: '.$files['skipped']);
        } else {
            $this->info("Ficheiros: {$files['file']} ({$files['count']} ficheiros, {$mb($files['bytes'])})");
        }
        $this->line("Cópias antigas apagadas: {$result['pruned']}");

        return self::SUCCESS;
    }
}
