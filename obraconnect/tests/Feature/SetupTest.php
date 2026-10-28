<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Support\Env;
use Tests\TestCase;

class SetupTest extends TestCase
{
    private string $token = 'token-de-instalacao-com-mais-de-32-caracteres';

    public function test_setup_is_disabled_without_token(): void
    {
        config(['obraconnect.setup_token' => null]);

        $this->get('/instalar')->assertNotFound();
    }

    public function test_setup_creates_super_admin_and_disables_itself(): void
    {
        config(['obraconnect.setup_token' => $this->token]);

        $this->get('/instalar')->assertOk()->assertSee('Requisitos');

        $this->post('/instalar', ['action' => 'admin', 'token' => 'errado', 'name' => 'X'])->assertForbidden();

        $this->post('/instalar', [
            'action' => 'admin',
            'token' => $this->token,
            'name' => 'Administrador',
            'email' => 'admin@obraconnect.pt',
            'password' => 'Segura2026obra',
            'password_confirmation' => 'Segura2026obra',
        ])->assertOk()->assertSee('Super administrador criado');

        $user = User::query()->where('email', 'admin@obraconnect.pt')->firstOrFail();
        $this->assertSame(PlatformRole::SuperAdmin, $user->platform_role);

        $this->get('/instalar')->assertNotFound();
    }

    public function test_short_tokens_are_not_accepted(): void
    {
        config(['obraconnect.setup_token' => 'curto']);

        $this->get('/instalar')->assertNotFound();
    }

    public function test_setup_generates_app_key_when_missing(): void
    {
        $dir = sys_get_temp_dir().'/obra-setup-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_NAME=ObraConnect\nAPP_KEY=\nDB_CONNECTION=mysql\n");

        // Arranca a aplicação de raiz SEM chave, como numa instalação nova com
        // SESSION_ENCRYPT=true (valor de produção do .env.example).
        $previousKey = getenv('APP_KEY');
        $this->setEnv('APP_KEY', '');
        $this->setEnv('SESSION_ENCRYPT', 'true');
        $this->setEnv('SESSION_DRIVER', 'database');
        Env::enablePutenv(); // repositório novo: o .env local não sobrepõe estes valores
        $this->refreshApplication();
        $this->app->useEnvironmentPath($dir);
        $this->assertSame('', (string) config('app.key'));
        $this->assertSame('database', config('session.driver'));

        config(['obraconnect.setup_token' => $this->token]);

        $this->get('/instalar')->assertOk()->assertSee('Gerar APP_KEY');
        $this->post('/instalar', ['action' => 'key', 'token' => $this->token])
            ->assertOk()
            ->assertSee('APP_KEY gerada');

        $env = file_get_contents($dir.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $env);

        // Uma chave existente nunca é substituída.
        $before = $env;
        $this->post('/instalar', ['action' => 'key', 'token' => $this->token])->assertSee('já está definida');
        $this->assertSame($before, file_get_contents($dir.'/.env'));

        unlink($dir.'/.env');
        rmdir($dir);
        $this->setEnv('APP_KEY', (string) $previousKey);
        $this->setEnv('SESSION_ENCRYPT', 'false');
        $this->setEnv('SESSION_DRIVER', 'array');
        Env::enablePutenv();
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
