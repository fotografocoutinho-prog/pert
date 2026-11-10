<?php

namespace App\Monitoring;

use App\Backup\Backups;
use App\Enums\SupplierDocumentStatus;
use App\Models\SupplierDocument;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verificações do sistema para a equipa da plataforma: cron, tarefas agendadas, fila, tarefas
 * falhadas, DocIntel, erros de hoje e configuração. Cada verificação: ok, warning ou failing.
 * Só "failing" gera email (ver obraconnect:system-check).
 */
final class SystemHealth
{
    public const PULSE = 'obraconnect-pulso';

    /** Tarefas agendadas: nome => [descrição, minutos sem correr com êxito até contar como parada]. */
    public const TASKS = [
        self::PULSE => ['Sinal do cron (a cada minuto)', 5],
        'obraconnect-fila' => ['Fila: emails, pré-visualizações e leituras de faturas (a cada minuto)', 10],
        'obraconnect-sistema' => ['Verificação do sistema (a cada 10 minutos)', 30],
        'obraconnect-exportacoes' => ['Limpeza das exportações (de hora a hora)', 130],
        'obraconnect-webhooks' => ['Limpeza do registo de envios de webhooks (de hora a hora)', 130],
        'obraconnect-subscricoes' => ['Subscrições e períodos de experiência (02:15)', 26 * 60],
        'obraconnect-previsoes-alertas' => ['Previsões e alertas das obras (07:00)', 26 * 60],
        'obraconnect-escalonamento' => ['Ocorrências com o prazo ultrapassado (07:30)', 26 * 60],
        'obraconnect-backup' => ['Cópia de segurança diária (03:30)', 26 * 60],
        'obraconnect-tokens' => ['Limpeza de sessões da API (diária)', 26 * 60],
        'obraconnect-reposicoes' => ['Limpeza de pedidos de reposição de palavra-passe (diária)', 26 * 60],
        'obraconnect-falhados' => ['Limpeza de tarefas falhadas antigas (semanal)', 8 * 24 * 60],
    ];

    /** Uma tarefa na fila há mais do que isto (minutos) quer dizer que a fila não está a andar. */
    public const QUEUE_STUCK_MINUTES = 10;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<array{key: string, group: string, label: string, status: string, detail: string}> */
    public function checks(): array
    {
        $now = CarbonImmutable::now();
        $out = [$this->database()];
        $tasks = $this->taskRows();
        $since = $tasks->get(self::PULSE)?->created_at;
        $since = $since ? CarbonImmutable::parse($since) : null;

        foreach (self::TASKS as $name => [$label, $minutes]) {
            if ($name === 'obraconnect-fila' && ! config('obraconnect.queue.run_via_scheduler')) {
                continue;
            }
            $out[] = $this->task($name, $label, $minutes, $tasks->get($name), $since, $now);
        }

        $out[] = $this->queue($now);
        $out[] = $this->failedJobs($now);
        if ($docintel = $this->docintel($now)) {
            $out[] = $docintel;
        }
        $out[] = $this->errorsToday();
        $out[] = $this->mail();
        $out[] = $this->debug();
        if ($disk = $this->disk()) {
            $out[] = $disk;
        }
        $out[] = $this->backups($now);

        return $out;
    }

    /** Para o /health público: o cron corre? ok | stale | unknown (ainda sem sinal). */
    public function schedulerState(): string
    {
        try {
            $pulse = DB::table('system_tasks')->where('name', self::PULSE)->value('last_finished_at');
        } catch (Throwable) {
            return 'unknown';
        }

        return $pulse === null ? 'unknown' : (CarbonImmutable::parse($pulse)->gt(now()->subMinutes(self::TASKS[self::PULSE][1])) ? 'ok' : 'stale');
    }

    /** Para o /health público: a fila anda? ok | stuck */
    public function queueState(): string
    {
        try {
            return $this->stuckJobs(CarbonImmutable::now()) > 0 ? 'stuck' : 'ok';
        } catch (Throwable) {
            return 'ok';
        }
    }

    /** @return Collection<string, object> */
    public function taskRows()
    {
        try {
            return DB::table('system_tasks')->get()->keyBy('name');
        } catch (Throwable) {
            return collect();
        }
    }

    private static function check(string $key, string $group, string $label, string $status, string $detail): array
    {
        return compact('key', 'group', 'label', 'status', 'detail');
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return self::check('database', 'Base de dados', 'Base de dados', 'ok', 'Responde.');
        } catch (Throwable $e) {
            return self::check('database', 'Base de dados', 'Base de dados', 'failing', 'Não responde: '.Str::limit($e->getMessage(), 200));
        }
    }

    private function task(string $name, string $label, int $minutes, ?object $row, ?CarbonImmutable $since, CarbonImmutable $now): array
    {
        $key = 'task:'.$name;
        $at = fn ($t) => CarbonImmutable::parse($t)->timezone('Europe/Lisbon')->format('d/m/Y H:i');

        if ($row === null || $row->last_finished_at === null && $row->last_status !== 'running') {
            if ($name === self::PULSE) {
                return self::check($key, 'Cron', $label, 'warning', 'Ainda sem sinal do cron. Se continuar assim, confirme a tarefa cron do cPanel (schedule:run a cada minuto).');
            }
            // Uma tarefa que só existe a partir de uma versão nova conta desde que apareceu.
            if ($since !== null && $row?->created_at !== null && CarbonImmutable::parse($row->created_at)->gt($since)) {
                $since = CarbonImmutable::parse($row->created_at);
            }
            if ($since === null || $since->gt($now->subMinutes($minutes))) {
                return self::check($key, 'Tarefas agendadas', $label, 'ok', 'Ainda não chegou a hora de correr.');
            }

            return self::check($key, 'Tarefas agendadas', $label, 'failing', 'Nunca correu desde '.$at($since).'.');
        }

        $group = $name === self::PULSE ? 'Cron' : 'Tarefas agendadas';
        if ($row->last_status === 'running' && CarbonImmutable::parse($row->last_started_at)->lt($now->subMinutes(30))) {
            return self::check($key, $group, $label, 'warning', 'Começou a '.$at($row->last_started_at).' e ainda não acabou (pode ter sido interrompida pelo alojamento).');
        }
        if ($row->last_status === 'failed') {
            $times = (int) $row->consecutive_failures;

            return self::check($key, $group, $label, 'failing', 'Falhou a '.$at($row->last_finished_at).($times > 1 ? " ({$times} vezes seguidas)" : '').': '.Str::limit((string) $row->last_error, 300));
        }
        $success = $row->last_success_at ? CarbonImmutable::parse($row->last_success_at) : null;
        if ($success === null || $success->lt($now->subMinutes($minutes))) {
            return self::check($key, $group, $label, 'failing', $name === self::PULSE
                ? 'O cron não corre desde '.($success ? $at($success) : 'sempre').'. Sem ele não saem emails, alertas nem leituras de faturas.'
                : 'Não corre com êxito desde '.($success ? $at($success) : 'sempre').'.');
        }

        return self::check($key, $group, $label, 'ok', 'Última vez a '.$at($row->last_finished_at).' ('.number_format(((int) $row->last_duration_ms) / 1000, 1, ',', '').' s).');
    }

    private function stuckJobs(CarbonImmutable $now): int
    {
        return DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now->subMinutes(self::QUEUE_STUCK_MINUTES)->getTimestamp())->count();
    }

    private function queue(CarbonImmutable $now): array
    {
        if (config('queue.default') !== 'database') {
            return self::check('queue', 'Fila', 'Fila de trabalhos', 'ok', 'Fila '.config('queue.default').' (não é verificada aqui).');
        }
        $pending = DB::table('jobs')->count();
        $stuck = $this->stuckJobs($now);

        return $stuck > 0
            ? self::check('queue', 'Fila', 'Fila de trabalhos', 'failing', "{$stuck} trabalhos à espera há mais de ".self::QUEUE_STUCK_MINUTES.' minutos (emails e leituras atrasados).')
            : self::check('queue', 'Fila', 'Fila de trabalhos', 'ok', $pending === 0 ? 'Vazia.' : "{$pending} à espera, a andar.");
    }

    private function failedJobs(CarbonImmutable $now): array
    {
        $recent = DB::table('failed_jobs')->where('failed_at', '>=', $now->subDay())->get(['payload']);
        if ($recent->isEmpty()) {
            return self::check('failed_jobs', 'Fila', 'Trabalhos falhados (24 h)', 'ok', 'Nenhum.');
        }
        $names = $recent->map(fn ($j) => class_basename((string) (json_decode($j->payload, true)['displayName'] ?? '?')))->countBy()
            ->map(fn ($n, $name) => "{$name} ×{$n}")->implode(', ');

        return self::check('failed_jobs', 'Fila', 'Trabalhos falhados (24 h)', 'warning', $recent->count().' falharam depois de 3 tentativas: '.$names.'.');
    }

    private function docintel(CarbonImmutable $now): ?array
    {
        $url = (string) config('obraconnect.docintel.url');
        if ($url === '' || ! config('obraconnect.docintel.key')) {
            return null;
        }
        try {
            // O DocIntel em cPanel (Passenger) adormece quando não é usado e o primeiro pedido acorda-o, o
            // que pode demorar bastante: dá-se tempo e uma segunda tentativa antes de dizer que não responde.
            $ok = Http::timeout(30)->retry(2, 1000, throw: false)->acceptJson()->get($url.'/api/v1/health')->successful();
        } catch (Throwable) {
            $ok = false;
        }
        $stuck = $this->tenants->runWithoutTenant(fn () => SupplierDocument::query()
            ->where('status', SupplierDocumentStatus::Reading->value)->where('updated_at', '<=', $now->subMinutes(30))->count());

        if (! $ok) {
            return self::check('docintel', 'DocIntel', 'Leitura de faturas (DocIntel)', 'failing', "O DocIntel ({$url}) não responde. As faturas entram na mesma, mas ficam por ler.");
        }

        return $stuck > 0
            ? self::check('docintel', 'DocIntel', 'Leitura de faturas (DocIntel)', 'warning', "Responde, mas {$stuck} documentos estão em leitura há mais de 30 minutos.")
            : self::check('docintel', 'DocIntel', 'Leitura de faturas (DocIntel)', 'ok', 'Responde.');
    }

    private function errorsToday(): array
    {
        $file = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');
        if (! is_file($file)) {
            $file = storage_path('logs/laravel.log');
        }
        if (! is_file($file)) {
            return self::check('errors', 'Erros', 'Erros registados hoje', 'ok', 'Nenhum.');
        }
        $size = filesize($file);
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - 2_000_000));
        $text = (string) stream_get_contents($handle);
        fclose($handle);
        $today = now()->format('Y-m-d');
        preg_match_all('/^\['.preg_quote($today, '/').' [^\]]+\] \w+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $text, $m);
        if ($m[0] === []) {
            return self::check('errors', 'Erros', 'Erros registados hoje', 'ok', 'Nenhum.');
        }

        return self::check('errors', 'Erros', 'Erros registados hoje', 'warning', count($m[0]).' erros hoje. O último: '.Str::limit(end($m[1]), 240));
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true) && app()->environment('production')
            ? self::check('mail', 'Configuração', 'Envio de emails', 'failing', "MAIL_MAILER={$mailer}: os emails não saem do servidor.")
            : self::check('mail', 'Configuração', 'Envio de emails', 'ok', "Por {$mailer}.");
    }

    private function debug(): array
    {
        return config('app.debug') && app()->environment('production')
            ? self::check('debug', 'Configuração', 'Modo de depuração', 'warning', 'APP_DEBUG=true em produção mostra detalhes internos quando há erros. Ponha APP_DEBUG=false.')
            : self::check('debug', 'Configuração', 'Modo de depuração', 'ok', 'Desligado.');
    }

    private function disk(): ?array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return null;
        }
        $gb = $free / 1024 ** 3;

        return self::check('disk', 'Configuração', 'Espaço em disco', $gb < 1 ? 'warning' : 'ok',
            number_format($gb, 1, ',', ' ').' GB livres no disco do servidor (a quota da conta cPanel pode ser menor).');
    }

    private function backups(CarbonImmutable $now): array
    {
        try {
            $all = app(Backups::class)->list();
        } catch (Throwable $e) {
            return self::check('backups', 'Cópias de segurança', 'Cópias de segurança', 'failing', 'Não foi possível ler a pasta das cópias: '.Str::limit($e->getMessage(), 150));
        }
        $db = collect($all)->firstWhere('kind', 'db');
        if ($db === null) {
            return self::check('backups', 'Cópias de segurança', 'Cópias de segurança', 'ok', 'Ainda não há cópias: a primeira faz-se às 03:30.');
        }
        $at = $db['at']->timezone('Europe/Lisbon')->format('d/m/Y H:i');
        if ($db['at']->lt($now->subHours(26))) {
            return self::check('backups', 'Cópias de segurança', 'Cópias de segurança', 'failing', "A última cópia da base de dados é de {$at}.");
        }
        $files = collect($all)->firstWhere('kind', 'files');
        $mb = fn (int $b) => number_format($b / 1048576, 1, ',', ' ').' MB';

        return self::check('backups', 'Cópias de segurança', 'Cópias de segurança', 'ok', "Última a {$at}: base de dados {$mb($db['bytes'])}"
            .($files ? ", ficheiros {$mb($files['bytes'])}" : '').' · '.count($all).' ficheiros guardados ('.config('obraconnect.backup.keep_days')
            .' dias). Ficam só neste servidor: copie-os para fora com regularidade.');
    }
}
