<?php

namespace Tests\Feature\Platform;

use App\Backup\Backups;
use App\Monitoring\SystemHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    private string $dir;

    private string $files;

    protected function setUp(): void
    {
        parent::setUp();
        $base = storage_path('framework/testing/backup-'.uniqid());
        $this->dir = "{$base}/copias";
        $this->files = "{$base}/ficheiros";
        File::ensureDirectoryExists("{$this->files}/tenants/1/documents");
        File::ensureDirectoryExists("{$this->files}/tenants/1/exports");
        file_put_contents("{$this->files}/tenants/1/documents/projeto.pdf", "%PDF-1.4\n% teste\n");
        file_put_contents("{$this->files}/tenants/1/exports/export-1.zip", 'temporário');
        config(['obraconnect.backup.path' => $this->dir, 'filesystems.disks.private.driver' => 'local', 'filesystems.disks.private.root' => $this->files]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->dir));
        parent::tearDown();
    }

    public function test_the_database_dump_restores_into_an_empty_database_with_the_same_rows(): void
    {
        [$owner] = $this->createCompany('Construções «Aspas» & Filhos');
        $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => "Obra d'Oeste — 1.º lote", 'starts_on' => '2026-11-02', 'description' => "Linha 1\nLinha 2 com \\ e ' e \""])->assertCreated();

        $this->artisan('obraconnect:backup')->assertSuccessful();
        $db = glob("{$this->dir}/db-*.sql.gz");
        $this->assertCount(1, $db);
        $sql = gzdecode(file_get_contents($db[0]));
        $this->assertStringContainsString('CREATE TABLE "projects"', $sql);
        $this->assertStringEndsWith("linhas\n", $sql);

        // Restaura numa base vazia e compara tabela a tabela.
        $target = dirname($this->dir).'/restauro.sqlite';
        touch($target);
        config(['database.connections.restauro' => ['driver' => 'sqlite', 'database' => $target, 'foreign_key_constraints' => true, 'prefix' => '']]);
        DB::connection('restauro')->getPdo()->exec($sql);
        $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))->pluck('name');
        $this->assertGreaterThan(50, $tables->count());
        foreach ($tables as $t) {
            $this->assertSame(DB::table($t)->count(), DB::connection('restauro')->table($t)->count(), "Tabela {$t}");
        }
        $this->assertSame("Linha 1\nLinha 2 com \\ e ' e \"", DB::connection('restauro')->table('projects')->value('description'));
        $this->assertSame('Construções «Aspas» & Filhos', DB::connection('restauro')->table('tenants')->value('name'));
        DB::purge('restauro');
    }

    public function test_company_files_go_in_a_zip_without_temporary_exports(): void
    {
        $this->artisan('obraconnect:backup')->assertSuccessful();
        $zipFile = glob("{$this->dir}/files-*.zip")[0] ?? null;
        $this->assertNotNull($zipFile);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipFile));
        $this->assertNotFalse($zip->locateName('tenants/1/documents/projeto.pdf'));
        $this->assertFalse($zip->locateName('tenants/1/exports/export-1.zip'));
        $this->assertSame("%PDF-1.4\n% teste\n", $zip->getFromName('tenants/1/documents/projeto.pdf'));
        $zip->close();

        $this->travel(2)->seconds();
        $this->artisan('obraconnect:backup', ['--no-files' => true])->assertSuccessful();
        $this->assertCount(1, glob("{$this->dir}/files-*.zip"));
        $this->assertCount(2, glob("{$this->dir}/db-*.sql.gz"));
    }

    public function test_old_copies_are_pruned_but_the_last_three_stay_and_health_reports_them(): void
    {
        $status = fn () => collect(app(SystemHealth::class)->checks())->firstWhere('key', 'backups');
        $this->assertSame('ok', $status()['status']);
        $this->assertStringContainsString('Ainda não há cópias', $status()['detail']);

        File::ensureDirectoryExists($this->dir);
        foreach (['20260101-0330', '20260102-0330', '20260103-0330', '20260104-0330', '20260105-0330'] as $stamp) {
            file_put_contents("{$this->dir}/db-{$stamp}.sql.gz", gzencode('-- antigo'));
        }
        file_put_contents("{$this->dir}/notas.txt", 'não é uma cópia');
        $this->assertSame('failing', $status()['status']);

        $this->assertSame(2, app(Backups::class)->prune());
        $this->assertSame(['db-20260103-0330.sql.gz', 'db-20260104-0330.sql.gz', 'db-20260105-0330.sql.gz'],
            collect(glob("{$this->dir}/db-*.sql.gz"))->map(fn ($f) => basename($f))->sort()->values()->all());
        $this->assertFileExists("{$this->dir}/notas.txt");

        // Uma cópia nova: as antigas (mais de 14 dias) saem, ficando sempre 3.
        $this->artisan('obraconnect:backup')->assertSuccessful();
        $this->assertCount(3, glob("{$this->dir}/db-*.sql.gz"));
        $this->assertSame('ok', $status()['status']);

        // Zips de ficheiros: só os 3 últimos.
        foreach (['20261101-033000', '20261102-033000', '20261103-033000'] as $stamp) {
            file_put_contents("{$this->dir}/files-{$stamp}.zip", 'zip');
        }
        $this->assertSame(1, app(Backups::class)->prune());
        $this->assertFileDoesNotExist("{$this->dir}/files-20261101-033000.zip");
        $this->assertCount(3, glob("{$this->dir}/files-*.zip"));
        $this->assertStringContainsString('Última a', $status()['detail']);
    }
}
