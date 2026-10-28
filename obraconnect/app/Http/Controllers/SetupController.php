<?php

namespace App\Http\Controllers;

use App\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Instalação para alojamentos cPanel sem SSH:
 * 1. verifica requisitos (e gera a APP_KEY se faltar); 2. corre migrações e dados base;
 * 3. cria o super administrador.
 *
 * Só funciona com SETUP_TOKEN (32+ caracteres) no .env e enquanto não existir
 * nenhum super administrador.
 */
class SetupController extends Controller
{
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'dom', 'ctype', 'curl', 'fileinfo', 'zip', 'session',
    ];

    public function show(): Response
    {
        if (! $this->enabled()) {
            return response()->view('setup', ['disabled' => true], 404);
        }

        return response()->view('setup', $this->state());
    }

    public function store(Request $request): Response
    {
        if (! $this->enabled()) {
            return response()->view('setup', ['disabled' => true], 404);
        }

        if (! hash_equals((string) config('obraconnect.setup_token'), (string) $request->input('token'))) {
            Log::warning('Tentativa de instalação com token inválido', ['ip' => $request->ip()]);
            usleep(800_000);

            return response()->view('setup', $this->state() + ['error' => 'Token de instalação inválido.'], 403);
        }

        return match ($request->input('action')) {
            'key' => $this->generateKey(),
            'migrate' => $this->migrate(),
            'admin' => $this->createAdmin($request),
            default => response()->view('setup', $this->state(), 400),
        };
    }

    /**
     * Grava uma APP_KEY nova no .env. Só quando ainda não existe: trocar uma chave
     * em uso invalidaria sessões e segredos 2FA.
     */
    private function generateKey(): Response
    {
        if (filled(config('app.key'))) {
            return response()->view('setup', $this->state() + ['message' => 'A APP_KEY já está definida.']);
        }

        $envPath = app()->environmentFilePath();

        if (! is_file($envPath) || ! is_writable($envPath)) {
            return response()->view('setup', $this->state() + [
                'error' => 'O ficheiro .env não existe ou não tem permissão de escrita. Crie-o a partir do .env.example.',
            ], 500);
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = (string) file_get_contents($envPath);
        $contents = preg_match('/^APP_KEY=.*$/m', $contents)
            ? preg_replace_callback('/^APP_KEY=.*$/m', fn () => 'APP_KEY='.$key, $contents, 1)
            : 'APP_KEY='.$key.PHP_EOL.$contents;

        file_put_contents($envPath, $contents);
        config(['app.key' => $key]);

        Log::info('APP_KEY gerada pela página de instalação');

        return response()->view('setup', $this->state() + ['message' => 'APP_KEY gerada e guardada no .env.']);
    }

    private function migrate(): Response
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
            Artisan::call('obraconnect:sync-permissions');
            $output .= Artisan::output();

            if (DB::table('plans')->count() === 0) {
                (new PlanSeeder)->run();
                $output .= "Planos iniciais criados.\n";
            }
        } catch (Throwable $e) {
            report($e);

            return response()->view('setup', $this->state() + ['error' => 'Falha nas migrações: '.$e->getMessage()], 500);
        }

        return response()->view('setup', $this->state() + ['message' => 'Base de dados preparada.', 'output' => $output]);
    }

    private function createAdmin(Request $request): Response
    {
        $validator = Validator::make($request->only('name', 'email', 'password', 'password_confirmation'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->view('setup', $this->state() + ['errors_list' => $validator->errors()->all()], 422);
        }

        $user = User::query()->create([
            'name' => $request->input('name'),
            'email' => Str::lower($request->input('email')),
            'password' => $request->input('password'),
        ]);
        $user->forceFill(['platform_role' => PlatformRole::SuperAdmin, 'email_verified_at' => now()])->save();

        Log::info('Super administrador criado pela página de instalação', ['user_id' => $user->id]);

        return response()->view('setup', ['done' => true, 'disabled' => true]);
    }

    private function enabled(): bool
    {
        if (strlen((string) config('obraconnect.setup_token')) < 32) {
            return false;
        }

        try {
            return ! Schema::hasTable('users')
                || ! User::query()->where('platform_role', PlatformRole::SuperAdmin->value)->exists();
        } catch (Throwable) {
            // Sem ligação à BD: mostra a página para o diagnóstico aparecer.
            return true;
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $checks = [
            ['label' => 'PHP 8.3 ou superior (atual: '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')],
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = ['label' => "Extensão PHP {$extension}", 'ok' => extension_loaded($extension)];
        }

        $checks[] = ['label' => 'storage/ com permissão de escrita', 'ok' => is_writable(storage_path())];
        $checks[] = ['label' => 'bootstrap/cache/ com permissão de escrita', 'ok' => is_writable(base_path('bootstrap/cache'))];
        $checks[] = ['label' => 'APP_KEY definida no .env', 'ok' => filled(config('app.key'))];
        $checks[] = ['label' => 'APP_DEBUG=false (recomendado em produção)', 'ok' => ! config('app.debug'), 'warning' => true];

        $database = ['ok' => false, 'error' => null, 'pending' => null];
        try {
            DB::connection()->getPdo();
            $database['ok'] = true;
            $database['pending'] = $this->pendingMigrations();
        } catch (Throwable $e) {
            $database['error'] = $e->getMessage();
        }

        return ['checks' => $checks, 'database' => $database, 'app_key_missing' => blank(config('app.key'))];
    }

    private function pendingMigrations(): int
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([database_path('migrations')]);

        if (! $migrator->repositoryExists()) {
            return count($files);
        }

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }
}
