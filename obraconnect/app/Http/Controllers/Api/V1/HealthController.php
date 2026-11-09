<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Monitoring\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Estado público, sem detalhes: base de dados, cron e fila. Responde 503 quando algum falha, para um
 * serviço de monitorização externo (ex.: UptimeRobot) avisar — é a única forma de saber que o cron
 * parou, porque sem cron também não saem os avisos do próprio ObraConnect.
 */
class HealthController extends Controller
{
    public function __invoke(SystemHealth $health): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'error';
        }
        $scheduler = $database === 'ok' ? $health->schedulerState() : 'unknown';
        $queue = $database === 'ok' ? $health->queueState() : 'ok';
        $ok = $database === 'ok' && $scheduler !== 'stale' && $queue === 'ok';

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'version' => config('obraconnect.version'),
            'database' => $database,
            'scheduler' => $scheduler,
            'queue' => $queue,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }
}
