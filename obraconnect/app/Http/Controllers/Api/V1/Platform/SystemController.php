<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Monitoring\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saúde do sistema no backoffice: verificações, tarefas agendadas e trabalhos falhados (que o
 * super administrador pode repetir ou apagar).
 */
class SystemController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function show(SystemHealth $health): JsonResponse
    {
        $tasks = $health->taskRows();
        $iso = fn ($t) => $t ? CarbonImmutable::parse($t)->toIso8601String() : null;
        $states = DB::table('system_checks')->get()->keyBy('key');

        return response()->json(['data' => [
            'checked_at' => now()->toIso8601String(),
            'checks' => array_map(fn (array $c) => $c + ['since' => $iso($states->get($c['key'])?->since)], $health->checks()),
            'tasks' => collect(SystemHealth::TASKS)->map(fn ($def, $name) => [
                'name' => $name,
                'label' => $def[0],
                'last_started_at' => $iso($tasks->get($name)?->last_started_at),
                'last_finished_at' => $iso($tasks->get($name)?->last_finished_at),
                'last_success_at' => $iso($tasks->get($name)?->last_success_at),
                'last_status' => $tasks->get($name)?->last_status,
                'last_duration_ms' => $tasks->get($name)?->last_duration_ms,
                'last_error' => $tasks->get($name)?->last_error,
                'runs' => (int) ($tasks->get($name)?->runs ?? 0),
            ])->values()->all(),
            'failed_jobs' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->get()->map(fn ($j) => [
                'uuid' => $j->uuid,
                'queue' => $j->queue,
                'job' => class_basename((string) (json_decode($j->payload, true)['displayName'] ?? '?')),
                'error' => Str::limit(trim(Str::before((string) $j->exception, "\n")), 300),
                'failed_at' => $iso($j->failed_at),
            ])->all(),
            'info' => [
                'version' => config('obraconnect.version'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
                'mailer' => config('mail.default'),
                'queue' => config('queue.default'),
                'docintel' => filled(config('obraconnect.docintel.url')) && filled(config('obraconnect.docintel.key')),
                'alert_recipients' => User::query()->where('platform_role', PlatformRole::SuperAdmin->value)->count() + count(config('obraconnect.monitoring.emails', [])),
            ],
        ]]);
    }

    /** Volta a pôr na fila um trabalho falhado (super administrador). */
    public function retry(Request $request, string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->auditor->record('platform.failed_job_retried', null, ['uuid' => $uuid], tenantId: false);

        return response()->json(['data' => ['retried' => $uuid]]);
    }

    public function forget(Request $request, string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->auditor->record('platform.failed_job_forgotten', null, ['uuid' => $uuid], tenantId: false);

        return response()->json(['data' => ['forgotten' => $uuid]]);
    }
}
