<?php

namespace Tests;

use App\Access\PermissionSynchronizer;
use App\Actions\Tenancy\RegisterCompany;
use App\Enums\PlatformRole;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Pedidos com Referer local são tratados como vindos da SPA (sessão Sanctum). */
    protected array $spaHeaders = ['Referer' => 'http://localhost/app', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSynchronizer::class)->sync();
        $this->seed(PlanSeeder::class);
    }

    /**
     * Cria uma empresa completa (perfis, administrador, trial) como no registo real.
     *
     * @return array{0: User, 1: Tenant}
     */
    protected function createCompany(string $name = 'Empresa A', ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $tenant = app(RegisterCompany::class)->handle($owner, ['name' => $name]);

        return [$owner->refresh(), $tenant];
    }

    protected function addMember(Tenant $tenant, string $roleKey, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        app(TenantContext::class)->runAs($tenant, function () use ($user, $roleKey): void {
            Membership::query()->create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->value('id'),
                'joined_at' => now(),
            ]);
        });

        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return $user->refresh();
    }

    protected function platformUser(PlatformRole $role = PlatformRole::SuperAdmin, bool $withMfa = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(array_filter([
            'platform_role' => $role,
            'two_factor_secret' => $withMfa ? encrypt('JBSWY3DPEHPK3PXP') : null,
            'two_factor_confirmed_at' => $withMfa ? now() : null,
        ]))->save();

        return $user;
    }

    protected function roleId(Tenant $tenant, string $key): int
    {
        return app(TenantContext::class)->runAs($tenant, fn () => (int) Role::query()->where('key', $key)->value('id'));
    }
}
