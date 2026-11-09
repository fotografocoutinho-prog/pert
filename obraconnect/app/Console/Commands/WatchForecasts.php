<?php

namespace App\Console\Commands;

use App\Alerts\ForecastWatch;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Todos os dias: fotografia semanal das previsões de cada obra e alertas automáticos (abre, fecha e
 * avisa por email). Com --no-email os alertas novos ficam para o envio seguinte.
 */
#[Signature('obraconnect:forecast-watch {--tenant=* : só estas empresas (id)} {--no-email : não enviar emails agora}')]
#[Description('Atualiza o histórico das previsões e os alertas automáticos das obras')]
class WatchForecasts extends Command
{
    public function handle(ForecastWatch $watch): int
    {
        $today = CarbonImmutable::today('Europe/Lisbon');
        $totals = ['snapshots' => 0, 'open' => 0, 'opened' => 0, 'resolved' => 0, 'emails' => 0];

        Tenant::query()->where('status', TenantStatus::Active->value)
            ->when($this->option('tenant'), fn ($q, $ids) => $q->whereKey($ids))
            ->lazyById()
            ->each(function (Tenant $tenant) use ($watch, $today, &$totals): void {
                foreach ($watch->run($tenant, $today, ! $this->option('no-email')) as $k => $v) {
                    $totals[$k] += $v;
                }
            });

        $this->info("Previsões guardadas: {$totals['snapshots']} · alertas abertos: {$totals['open']} (novos {$totals['opened']}, fechados {$totals['resolved']}) · emails: {$totals['emails']}");

        return self::SUCCESS;
    }
}
