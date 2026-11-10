<?php

use App\Monitoring\TaskMonitor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Em cPanel basta UMA tarefa cron por minuto:
|   * * * * * /usr/local/bin/php /home/UTILIZADOR/obraconnect/artisan schedule:run >> /dev/null 2>&1
| (o caminho do PHP varia: ver docs/07-guia-cpanel.md)
|
| Tudo com Schedule::call() + Artisan::call(), NUNCA Schedule::command(): o Schedule::command()
| lança cada tarefa num processo à parte (proc_open) e muitos alojamentos cPanel têm o proc_open
| desativado. A tarefa falhava em silêncio e os emails ficavam na fila para sempre. Assim corre
| tudo dentro do próprio schedule:run. Com Schedule::call() o name() vem ANTES do withoutOverlapping().
*/

// Cada tarefa fica registada em system_tasks (TaskMonitor): é assim que o backoffice e o /health sabem
// se o cron está vivo e se os emails, alertas e leituras estão a sair.
$run = fn (string $name, string $command, array $args = []) => fn () => TaskMonitor::run($name, fn () => Artisan::call($command, $args));

// Sinal de vida do cron (a cada minuto).
Schedule::call(fn () => TaskMonitor::pulse())->name('obraconnect-pulso')->everyMinute();

// Sem supervisor: um worker por minuto processa a fila e termina quando esvazia.
if (config('obraconnect.queue.run_via_scheduler')) {
    Schedule::call($run('obraconnect-fila', 'queue:work', [
        '--stop-when-empty' => true,
        '--max-time' => 50,
        '--tries' => 3,
        '--sleep' => 1,
    ]))
        ->name('obraconnect-fila')
        ->everyMinute()
        ->withoutOverlapping(5);
}

Schedule::call($run('obraconnect-sistema', 'obraconnect:system-check'))
    ->name('obraconnect-sistema')
    ->everyTenMinutes()
    ->withoutOverlapping(10);
Schedule::call($run('obraconnect-subscricoes', 'obraconnect:subscriptions-sweep'))
    ->name('obraconnect-subscricoes')
    ->dailyAt('02:15')
    ->withoutOverlapping();
Schedule::call($run('obraconnect-previsoes-alertas', 'obraconnect:forecast-watch'))
    ->name('obraconnect-previsoes-alertas')
    ->dailyAt('07:00')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping();
Schedule::call($run('obraconnect-escalonamento', 'obraconnect:escalate-issues'))
    ->name('obraconnect-escalonamento')
    ->dailyAt('07:30')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping();
Schedule::call($run('obraconnect-exportacoes', 'obraconnect:prune-exports'))->hourly()->name('obraconnect-exportacoes');
Schedule::call($run('obraconnect-webhooks', 'obraconnect:prune-webhooks'))->hourly()->name('obraconnect-webhooks');
Schedule::call($run('obraconnect-tokens', 'sanctum:prune-expired', ['--hours' => 24]))->daily()->name('obraconnect-tokens');
Schedule::call($run('obraconnect-reposicoes', 'auth:clear-resets'))->daily()->name('obraconnect-reposicoes');
Schedule::call($run('obraconnect-falhados', 'queue:prune-failed', ['--hours' => 720]))->weekly()->name('obraconnect-falhados');
