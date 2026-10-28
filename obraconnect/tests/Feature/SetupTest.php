<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Models\User;
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
}
