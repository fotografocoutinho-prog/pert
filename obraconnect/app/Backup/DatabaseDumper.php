<?php

namespace App\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Dump da base de dados em SQL, só com PDO (no cPanel o proc_open está desligado, por isso não dá para
 * chamar o mysqldump a partir da aplicação). MySQL/MariaDB e SQLite. O ficheiro .sql.gz importa-se com
 * o cliente normal: `gunzip -c ficheiro.sql.gz | mysql base` (ou phpMyAdmin → Importar).
 */
final class DatabaseDumper
{
    private const ROWS_PER_INSERT = 200;

    /** @return array{tables: int, rows: int, bytes: int} */
    public function dump(string $path, ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $gz = gzopen($path, 'wb6') ?: throw new RuntimeException("Não foi possível escrever {$path}.");
        $tables = 0;
        $rows = 0;

        try {
            $this->write($gz, '-- ObraConnect '.config('obraconnect.version').' — cópia de segurança da base de dados ('.$driver.') '.now()->toIso8601String()."\n");
            if ($driver === 'sqlite') {
                $this->write($gz, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
                foreach ($db->select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $t) {
                    $this->write($gz, "DROP TABLE IF EXISTS \"{$t->name}\";\n{$t->sql};\n");
                    $rows += $this->rows($db, $gz, $t->name, '"');
                    $tables++;
                }
                foreach ($db->select("select sql from sqlite_master where type = 'index' and sql is not null order by name") as $i) {
                    $this->write($gz, "{$i->sql};\n");
                }
                $this->write($gz, "COMMIT;\nPRAGMA foreign_keys=ON;\n");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $this->write($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n");
                $db->statement("SET time_zone = '+00:00'");
                foreach ($db->select("show full tables where Table_type = 'BASE TABLE'") as $row) {
                    $name = array_values((array) $row)[0];
                    $create = array_values((array) $db->selectOne("show create table `{$name}`"))[1];
                    $this->write($gz, "\nDROP TABLE IF EXISTS `{$name}`;\n{$create};\n");
                    $rows += $this->rows($db, $gz, $name, '`');
                    $tables++;
                }
                $this->write($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            } else {
                throw new RuntimeException("Base de dados {$driver} não suportada pela cópia da aplicação.");
            }
            $this->write($gz, "-- fim: {$tables} tabelas, {$rows} linhas\n");
        } finally {
            gzclose($gz);
        }

        return ['tables' => $tables, 'rows' => $rows, 'bytes' => (int) filesize($path)];
    }

    /** @param resource $gz */
    private function rows(Connection $db, $gz, string $table, string $q): int
    {
        $pdo = $db->getPdo();
        $mysql = $db->getDriverName() !== 'sqlite';
        // Sem guardar a tabela toda em memória (MySQL: resultado não "buffered"; só se lê e escreve no ficheiro).
        if ($mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $stmt = $pdo->query("select * from {$q}{$table}{$q}");
            $count = 0;
            $batch = [];
            $columns = null;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns ??= implode(', ', array_map(fn ($c) => $q.$c.$q, array_keys($row)));
                $batch[] = '('.implode(', ', array_map(fn ($v) => $this->value($pdo, $v), $row)).')';
                $count++;
                if (count($batch) === self::ROWS_PER_INSERT) {
                    $this->write($gz, "INSERT INTO {$q}{$table}{$q} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->write($gz, "INSERT INTO {$q}{$table}{$q} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
            $stmt->closeCursor();

            return $count;
        } finally {
            if ($mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    private function value(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = (string) $v;
        // Binário (não UTF-8): em hexadecimal, para não estragar o ficheiro.
        if (! mb_check_encoding($s, 'UTF-8')) {
            return "X'".bin2hex($s)."'";
        }

        return $pdo->quote($s);
    }

    /** @param resource $gz */
    private function write($gz, string $text): void
    {
        if (gzwrite($gz, $text) === false) {
            throw new RuntimeException('Falhou a escrita da cópia de segurança (disco cheio?).');
        }
    }
}
