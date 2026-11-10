<?php

namespace App\Backup;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Cópias de segurança diárias feitas pela aplicação (além das do alojamento): base de dados em
 * db-AAAAMMDD-HHMM.sql.gz e ficheiros das empresas em files-AAAAMMDD-HHMM.zip, na pasta privada
 * storage/app/backups (fora do public_html, não muda com as atualizações). Guarda 14 dias (e sempre as
 * 3 últimas). Uma cópia que só existe no servidor não protege contra a perda do servidor: copiar para
 * fora (ver docs/11-backup-restauro.md).
 */
final class Backups
{
    public function __construct(private readonly DatabaseDumper $dumper) {}

    public function path(): string
    {
        return rtrim((string) config('obraconnect.backup.path'), '/\\');
    }

    /** @return array{db: array<string, mixed>, files: array<string, mixed>|null, pruned: int} */
    public function run(bool $withFiles = true): array
    {
        $dir = $this->path();
        File::ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);
        $stamp = now('Europe/Lisbon')->format('Ymd-His');
        $old = umask(0077);

        try {
            $dbFile = "{$dir}/db-{$stamp}.sql.gz";
            $tmp = $dbFile.'.part';
            $db = $this->dumper->dump($tmp);
            rename($tmp, $dbFile);
            $db['file'] = basename($dbFile);

            $files = null;
            if ($withFiles && config('obraconnect.backup.files', true)) {
                $files = $this->files("{$dir}/files-{$stamp}.zip");
            }
        } finally {
            umask($old);
            foreach (glob("{$dir}/*.part") ?: [] as $part) {
                @unlink($part);
            }
        }

        return ['db' => $db, 'files' => $files, 'pruned' => $this->prune()];
    }

    /**
     * @return list<array{file: string, kind: string, bytes: int, at: CarbonImmutable}> mais recentes primeiro
     */
    public function list(): array
    {
        $out = [];
        $dir = $this->path();
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (! preg_match('/^(db|files)-(\d{8})-(\d{4,6})\.(sql\.gz|zip)$/', $name, $m)) {
                continue;
            }
            $f = "{$dir}/{$name}";
            $out[] = ['file' => $name, 'kind' => $m[1], 'bytes' => (int) filesize($f),
                'at' => CarbonImmutable::createFromFormat(strlen($m[3]) === 6 ? 'YmdHis' : 'YmdHi', $m[2].$m[3], 'Europe/Lisbon')];
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $out;
    }

    /** Apaga as cópias com mais de N dias, mas guarda sempre as 3 últimas de cada tipo. */
    /**
     * Base de dados: apaga as cópias com mais de N dias (guarda sempre as 3 últimas). Ficheiros: guarda só
     * as últimas N (por omissão 3), porque cada zip tem tudo e a quota do alojamento é limitada.
     */
    public function prune(): int
    {
        $limit = now()->subDays(max(1, (int) config('obraconnect.backup.keep_days', 14)));
        $all = $this->list();
        $deleted = 0;
        $db = array_values(array_filter($all, fn ($b) => $b['kind'] === 'db'));
        foreach (array_slice($db, 3) as $b) {
            if ($b['at']->lt($limit) && @unlink($this->path().'/'.$b['file'])) {
                $deleted++;
            }
        }
        $files = array_values(array_filter($all, fn ($b) => $b['kind'] === 'files'));
        foreach (array_slice($files, max(1, (int) config('obraconnect.backup.files_keep', 3))) as $b) {
            if (@unlink($this->path().'/'.$b['file'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array{file: string, count: int, bytes: int, skipped?: string}|null */
    private function files(string $target): ?array
    {
        $disk = config('obraconnect.files.disk');
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null; // ficheiros num serviço externo (S3…): ficam com as cópias desse serviço
        }
        $root = rtrim((string) config("filesystems.disks.{$disk}.root"), '/\\');
        if (! is_dir($root)) {
            return null;
        }

        $list = [];
        $total = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // As exportações são temporárias (48 h) e grandes: não entram.
            if (! $file->isFile() || preg_match('#(^|/)exports/#', $relative)) {
                continue;
            }
            $list[$relative] = $file->getPathname();
            $total += $file->getSize();
        }
        $max = (int) config('obraconnect.backup.files_max_mb', 5120) * 1048576;
        if ($total > $max) {
            return ['file' => '', 'count' => count($list), 'bytes' => $total, 'skipped' => 'Os ficheiros passam o limite da cópia da aplicação ('
                .(int) config('obraconnect.backup.files_max_mb').' MB): use a cópia do alojamento ou guarde-os noutro serviço.'];
        }

        if ($list === []) {
            return ['file' => '', 'count' => 0, 'bytes' => 0];
        }
        $zip = new ZipArchive;
        $tmp = $target.'.part';
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar {$target}.");
        }
        foreach ($list as $relative => $absolute) {
            $zip->addFile($absolute, $relative);
            // Fotografias e PDFs já vêm comprimidos: guardar sem voltar a comprimir (rápido, pouco CPU).
            $zip->setCompressionName($relative, ZipArchive::CM_STORE);
        }
        if (! $zip->close()) {
            throw new RuntimeException('Falhou o fecho do ficheiro da cópia (disco cheio?).');
        }
        rename($tmp, $target);

        return ['file' => basename($target), 'count' => count($list), 'bytes' => (int) filesize($target)];
    }
}
