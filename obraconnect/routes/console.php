<?php

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

// Sem supervisor: um worker por minuto processa a fila e termina quando esvazia.
if (config('obraconnect.queue.run_via_scheduler')) {
    Schedule::call(fn () => Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--max-time' => 50,
        '--tries' => 3,
        '--sleep' => 1,
    ]))
        ->name('obraconnect-fila')
        ->everyMinute()
        ->withoutOverlapping(5);
}

Schedule::call(fn () => Artisan::call('obraconnect:subscriptions-sweep'))
    ->name('obraconnect-subscricoes')
    ->dailyAt('02:15')
    ->withoutOverlapping();
Schedule::call(fn () => Artisan::call('obraconnect:prune-exports'))->hourly()->name('obraconnect-exportacoes');
Schedule::call(fn () => Artisan::call('sanctum:prune-expired', ['--hours' => 24]))->daily()->name('obraconnect-tokens');
Schedule::call(fn () => Artisan::call('auth:clear-resets'))->daily()->name('obraconnect-reposicoes');
Schedule::call(fn () => Artisan::call('queue:prune-failed', ['--hours' => 720]))->weekly()->name('obraconnect-falhados');
