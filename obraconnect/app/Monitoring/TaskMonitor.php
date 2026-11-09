<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Regista cada corrida das tarefas agendadas em system_tasks (início, fim, duração, erro). Um comando
 * Artisan que devolve um código diferente de 0 conta como falhado, com o fim do que escreveu.
 */
final class TaskMonitor
{
    /** @param  callable(): (int|null|void)  $task */
    public static function run(string $name, callable $task): int
    {
        $started = now();
        self::safely(fn () => self::touch($name, ['last_started_at' => $started, 'last_status' => 'running']));
        $clock = hrtime(true);

        try {
            $code = (int) ($task() ?? 0);
        } catch (Throwable $e) {
            self::finish($name, $clock, false, Str::limit(class_basename($e).': '.$e->getMessage(), 1000));
            throw $e;
        }

        $error = $code === 0 ? null : Str::limit("Código {$code}. ".trim(Str::afterLast(trim(Artisan::output()), "\n")), 1000);
        self::finish($name, $clock, $code === 0, $error);

        return $code;
    }

    /** O cron está vivo: corre a cada minuto e não faz mais nada. */
    public static function pulse(): void
    {
        self::run(SystemHealth::PULSE, fn () => 0);
    }

    private static function finish(string $name, int $clock, bool $ok, ?string $error): void
    {
        self::safely(fn () => self::write($name, $clock, $ok, $error));
    }

    /** O registo nunca pode fazer falhar a tarefa (ex.: durante a atualização, antes da migração). */
    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function write(string $name, int $clock, bool $ok, ?string $error): void
    {
        $now = now();
        $row = DB::table('system_tasks')->where('name', $name)->first();
        DB::table('system_tasks')->where('name', $name)->update([
            'last_finished_at' => $now,
            'last_success_at' => $ok ? $now : $row?->last_success_at,
            'last_status' => $ok ? 'ok' : 'failed',
            'last_duration_ms' => (int) min(4294967295, intdiv(hrtime(true) - $clock, 1_000_000)),
            'last_error' => $ok ? null : $error,
            'consecutive_failures' => $ok ? 0 : (int) ($row->consecutive_failures ?? 0) + 1,
            'runs' => (int) ($row->runs ?? 0) + 1,
            'updated_at' => $now,
        ]);
    }

    private static function touch(string $name, array $values): void
    {
        $now = now();
        $updated = DB::table('system_tasks')->where('name', $name)->update($values + ['updated_at' => $now]);
        if ($updated === 0) {
            DB::table('system_tasks')->insertOrIgnore($values + ['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
