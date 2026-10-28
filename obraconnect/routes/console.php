<?php

use Illuminate\Support\Facades\Schedule;

/*
| Em cPanel basta UMA tarefa cron por minuto:
|   * * * * * /usr/local/bin/php /home/UTILIZADOR/obraconnect/artisan schedule:run >> /dev/null 2>&1
| (o caminho do PHP varia: ver docs/07-guia-cpanel.md)
*/

// Sem supervisor: um worker por minuto processa a fila e termina quando esvazia.
if (config('obraconnect.queue.run_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground();
}

Schedule::command('obraconnect:subscriptions-sweep')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('obraconnect:prune-exports')->hourly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::command('queue:prune-failed --hours=720')->weekly();
