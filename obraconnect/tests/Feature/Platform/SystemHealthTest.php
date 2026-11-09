<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Mail\SystemAlertMail;
use App\Monitoring\SystemHealth;
use App\Monitoring\TaskMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Sem a fila pelo agendador (nos testes a fila é síncrona): fica só o sinal do cron e as tarefas.
        config(['obraconnect.queue.run_via_scheduler' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(string $name): object
    {
        return DB::table('system_tasks')->where('name', $name)->first();
    }

    public function test_task_runs_are_recorded_and_failures_never_hidden(): void
    {
        $this->assertSame(0, TaskMonitor::run('obraconnect-exportacoes', fn () => 0));
        $this->assertSame('ok', $this->task('obraconnect-exportacoes')->last_status);

        // Um comando que devolve 1 conta como falhado; uma exceção também, e volta a ser lançada.
        $this->assertSame(1, TaskMonitor::run('obraconnect-tokens', fn () => 1));
        $this->assertSame('failed', $this->task('obraconnect-tokens')->last_status);
        try {
            TaskMonitor::run('obraconnect-tokens', fn () => throw new RuntimeException('Sem ligação ao SMTP'));
            $this->fail('A exceção devia passar.');
        } catch (RuntimeException) {
        }
        $row = $this->task('obraconnect-tokens');
        $this->assertSame(2, (int) $row->consecutive_failures);
        $this->assertStringContainsString('Sem ligação ao SMTP', $row->last_error);
        $this->assertSame(3, DB::table('system_tasks')->sum('runs') + 0);
    }

    public function test_public_health_reports_a_stopped_cron_or_a_stuck_queue(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        // Antes do primeiro sinal do cron: não se sabe, mas não é erro.
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('scheduler', 'unknown');

        TaskMonitor::pulse();
        $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok']);

        // Seis minutos sem sinal: 503, para o monitor externo avisar.
        Carbon::setTestNow('2026-11-10 10:06:00');
        $this->getJson('/api/v1/health')->assertStatus(503)->assertJson(['status' => 'degraded', 'scheduler' => 'stale']);

        // Cron de volta, mas um trabalho parado na fila há 15 minutos.
        TaskMonitor::pulse();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp()]);
        $this->getJson('/api/v1/health')->assertStatus(503)->assertJson(['scheduler' => 'ok', 'queue' => 'stuck']);
    }

    public function test_system_check_emails_super_admins_when_something_breaks_and_when_it_recovers(): void
    {
        Mail::fake();
        config(['obraconnect.monitoring.emails' => ['avisos@exemplo.pt']]);
        $admin = $this->platformUser();
        $this->platformUser(PlatformRole::Support);

        Carbon::setTestNow('2026-11-10 10:00:00');
        TaskMonitor::pulse();
        $this->artisan('obraconnect:system-check')->assertSuccessful();
        Mail::assertNothingSent();

        // O cron para: um aviso aos super administradores e aos endereços configurados (não ao suporte).
        Carbon::setTestNow('2026-11-10 10:10:00');
        $this->artisan('obraconnect:system-check')->assertSuccessful();
        Mail::assertSent(SystemAlertMail::class, fn (SystemAlertMail $m) => $m->hasTo($admin->email) && $m->hasTo('avisos@exemplo.pt')
            && count($m->failing) === 1 && Str::contains($m->failing[0]['detail'], 'O cron não corre desde'));
        Mail::assertSentCount(1);

        // Dez minutos depois continua parado: não repete (só uma vez por dia).
        Carbon::setTestNow('2026-11-10 10:20:00');
        $this->artisan('obraconnect:system-check')->assertSuccessful();
        Mail::assertSentCount(1);

        // No dia seguinte ainda parado: lembrete do cron, e entretanto as tarefas de hora a hora também falharam.
        Carbon::setTestNow('2026-11-11 10:21:00');
        $this->artisan('obraconnect:system-check')->assertSuccessful();
        Mail::assertSent(SystemAlertMail::class, fn (SystemAlertMail $m) => in_array('task:obraconnect-pulso', array_column($m->still, 'key'), true)
            && in_array('task:obraconnect-exportacoes', array_column($m->failing, 'key'), true));

        // Volta a correr: aviso de que está tudo normal outra vez.
        TaskMonitor::pulse();
        foreach (array_keys(SystemHealth::TASKS) as $name) {
            TaskMonitor::run($name, fn () => 0);
        }
        $this->artisan('obraconnect:system-check')->assertSuccessful();
        Mail::assertSent(SystemAlertMail::class, fn (SystemAlertMail $m) => $m->failing === [] && $m->still === [] && count($m->recovered) >= 1);
        $this->assertSame('ok', DB::table('system_checks')->where('key', 'task:obraconnect-pulso')->value('status'));
    }

    public function test_backoffice_page_and_failed_jobs_only_for_platform_staff(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner)->getJson('/api/v1/platform/system')->assertForbidden();

        TaskMonitor::pulse();
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\ReadSupplierDocument', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => []]),
            'exception' => "RuntimeException: DocIntel não respondeu\n#0 trace", 'failed_at' => now(),
        ]);

        $support = $this->platformUser(PlatformRole::Support);
        $page = $this->actingAs($support)->getJson('/api/v1/platform/system')->assertOk();
        $this->assertSame('ok', collect($page->json('data.checks'))->firstWhere('key', 'task:obraconnect-pulso')['status']);
        $this->assertSame('warning', collect($page->json('data.checks'))->firstWhere('key', 'failed_jobs')['status']);
        $this->assertSame(['job' => 'ReadSupplierDocument', 'error' => 'RuntimeException: DocIntel não respondeu'], array_intersect_key($page->json('data.failed_jobs.0'), ['job' => 1, 'error' => 1]));
        $this->actingAs($support)->deleteJson("/api/v1/platform/system/failed-jobs/{$uuid}")->assertForbidden();

        $admin = $this->platformUser();
        $this->actingAs($admin)->postJson("/api/v1/platform/system/failed-jobs/{$uuid}/retry")->assertOk();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->actingAs($admin)->deleteJson("/api/v1/platform/system/failed-jobs/{$uuid}")->assertNotFound();
    }
}
