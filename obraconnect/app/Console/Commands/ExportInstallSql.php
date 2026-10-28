<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Gera o SQL completo de instalação (esquema + dados base) para importar no
 * phpMyAdmin do cPanel, sem SSH.
 *
 * As migrações e os seeders correm em modo "pretend" com a gramática MySQL do
 * Laravel: o SQL resultante é exatamente o que `php artisan migrate --seed`
 * executaria num MySQL 8 / MariaDB, e nada é escrito na base de dados.
 */
#[Signature('obraconnect:install-sql {path=database/sql/obraconnect-install.sql}')]
#[Description('Gera o ficheiro SQL de instalação para importar no phpMyAdmin')]
class ExportInstallSql extends Command
{
    public function handle(): int
    {
        $connection = $this->mysqlConnection();
        $previousDefault = config('database.default');
        $environment = app()->environment();

        // Tudo (Schema, modelos, seeders) passa pela ligação em modo pretend.
        DB::setDefaultConnection('install_sql');
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection('install_sql');

        try {
            $sql = $this->build($connection, $migrator);
        } finally {
            DB::setDefaultConnection($previousDefault);
            app()->instance('env', $environment);
        }

        $path = str_starts_with($this->argument('path'), '/') ? $this->argument('path') : base_path($this->argument('path'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $sql);

        $this->info("SQL de instalação escrito em {$this->argument('path')}");

        return self::SUCCESS;
    }

    private function build(Connection $connection, Migrator $migrator): string
    {
        $sections = [];

        $sections[] = $this->pretend($connection, fn () => $migrator->getRepository()->createRepository());

        foreach ($migrator->getMigrationFiles([database_path('migrations')]) as $name => $path) {
            $migration = (static fn (string $file) => require $file)($path);
            $statements = $this->pretend($connection, fn () => $migration->up());
            $statements[] = sprintf(
                'insert into `migrations` (`migration`, `batch`) values (%s, 1)',
                $connection->getPdo()->quote($name),
            );
            $sections[] = ["-- {$name}", ...$statements];
        }

        // Dados base (permissões e planos). Os dados de demonstração nunca entram.
        app()->instance('env', 'production');
        $sections[] = ['-- Dados base', ...$this->pretend($connection, fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())];

        return $this->header()
            ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n"
            .collect($sections)->map(fn (array $s) => implode("\n", array_map(
                fn (string $q) => str_starts_with($q, '--') ? $q : rtrim($q, "; \n").';',
                $s,
            )))->implode("\n\n")
            ."\n\nSET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /** Ligação com o driver "mysql" (gramática MySQL), mesmo que a local seja MariaDB. */
    private function mysqlConnection(): Connection
    {
        $base = config('database.connections.'.config('database.default'));

        if (! in_array($base['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Este comando precisa de uma ligação MySQL/MariaDB (só para obter a gramática; nada é escrito).');
            exit(self::FAILURE);
        }

        config(['database.connections.install_sql' => array_merge($base, ['driver' => 'mysql'])]);

        return DB::connection('install_sql');
    }

    /** @return list<string> Só escritas: as leituras feitas em modo pretend não interessam ao ficheiro. */
    private function pretend(Connection $connection, callable $callback): array
    {
        $grammar = $connection->getQueryGrammar();
        $writes = array_filter($connection->pretend($callback), fn (array $q) => ! preg_match('/^\s*select\b/i', $q['query']));

        return array_map(
            fn (array $q) => $q['bindings'] === []
                ? $q['query']
                : $grammar->substituteBindingsIntoRawSql($q['query'], $connection->prepareBindings($q['bindings'])),
            array_values($writes),
        );
    }

    private function header(): string
    {
        $version = config('obraconnect.version');

        return <<<SQL
        -- ObraConnect {$version} — SQL de instalação (esquema + dados base)
        -- Gerado por: php artisan obraconnect:install-sql
        --
        -- Uso no cPanel sem SSH:
        --   1. Crie a base de dados e o utilizador em "Bases de dados MySQL".
        --   2. Abra o phpMyAdmin, escolha a base de dados vazia e use "Importar" com este ficheiro.
        --   3. Configure o .env e abra /instalar para criar o super administrador.
        -- Compatível com MySQL 8.0+ e MariaDB 10.6+ (utf8mb4).
        -- Não contém dados pessoais nem dados de demonstração.


        SQL;
    }
}
