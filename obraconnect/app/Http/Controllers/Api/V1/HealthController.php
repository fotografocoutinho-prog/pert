<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'error';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'version' => config('obraconnect.version'),
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], $database === 'ok' ? 200 : 503);
    }
}
