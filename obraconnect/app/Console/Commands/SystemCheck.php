<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Mail\SystemAlertMail;
use App\Models\User;
use App\Monitoring\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Corre as verificações do sistema e guarda o estado de cada uma. Avisa por email (logo, sem passar pela
 * fila, que pode ser o que está avariado) quando uma passa a falhar, quando volta ao normal e, se
 * continuar a falhar, uma vez por dia.
 */
#[Signature('obraconnect:system-check {--no-email : só guardar o estado}')]
#[Description('Verifica o cron, as tarefas agendadas, a fila, o DocIntel e a configuração')]
class SystemCheck extends Command
{
    public function handle(SystemHealth $health): int
    {
        $now = now();
        $checks = $health->checks();
        $states = DB::table('system_checks')->get()->keyBy('key');
        $failing = $recovered = $still = [];

        foreach ($checks as $c) {
            $prev = $states->get($c['key']);
            if ($prev === null) {
                DB::table('system_checks')->insert(['key' => $c['key'], 'status' => $c['status'], 'detail' => $c['detail'], 'since' => $now, 'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($prev->status !== $c['status']) {
                DB::table('system_checks')->where('key', $c['key'])->update(['status' => $c['status'], 'detail' => $c['detail'], 'since' => $now, 'checked_at' => $now, 'notified_at' => null, 'updated_at' => $now]);
            } else {
                DB::table('system_checks')->where('key', $c['key'])->update(['detail' => $c['detail'], 'checked_at' => $now, 'updated_at' => $now]);
            }

            if ($c['status'] === 'failing' && ($prev === null || $prev->status !== 'failing')) {
                $failing[] = $c;
            } elseif ($c['status'] === 'failing' && ($prev->notified_at === null || $now->copy()->subDay()->gt($prev->notified_at))) {
                $still[] = $c;
            } elseif ($c['status'] !== 'failing' && $prev?->status === 'failing') {
                $recovered[] = $c;
            }
        }
        // Verificações que deixaram de existir (ex.: o DocIntel foi desligado).
        DB::table('system_checks')->whereNotIn('key', array_column($checks, 'key'))->delete();

        $sent = 0;
        if (($failing || $still || $recovered) && ! $this->option('no-email')) {
            $to = $this->recipients();
            if ($to !== []) {
                try {
                    Mail::to($to)->send(new SystemAlertMail($failing, $still, $recovered));
                    $sent = count($to);
                    DB::table('system_checks')->whereIn('key', array_column([...$failing, ...$still], 'key'))->update(['notified_at' => $now]);
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Não foi possível enviar o aviso: '.$e->getMessage());
                }
            }
        }

        $counts = collect($checks)->countBy('status');
        $this->info("Verificações: {$counts->get('ok', 0)} ok, {$counts->get('warning', 0)} avisos, {$counts->get('failing', 0)} a falhar · avisos enviados a {$sent} endereços");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $admins = User::query()->where('platform_role', PlatformRole::SuperAdmin->value)->pluck('email')->all();

        return array_values(array_unique(array_filter([...$admins, ...config('obraconnect.monitoring.emails', [])])));
    }
}
