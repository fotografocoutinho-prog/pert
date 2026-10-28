<?php

namespace Database\Seeders;

use App\Actions\Tenancy\RegisterCompany;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração para desenvolvimento local. Nunca correr em produção.
 * Passwords: "ObraConnect2026".
 */
class DemoSeeder extends Seeder
{
    public function run(RegisterCompany $registerCompany): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (! User::query()->where('email', 'admin@demo.obraconnect.test')->exists()) {
            $owner = User::query()->create([
                'name' => 'Ana Ferreira',
                'email' => 'admin@demo.obraconnect.test',
                'password' => 'ObraConnect2026',
            ]);
            $owner->forceFill(['email_verified_at' => now()])->save();

            $registerCompany->handle($owner, ['name' => 'Construções Demo, Lda.']);
        }

        if (! User::query()->where('email', 'plataforma@obraconnect.test')->exists()) {
            $admin = User::query()->create([
                'name' => 'Equipa ObraConnect',
                'email' => 'plataforma@obraconnect.test',
                'password' => 'ObraConnect2026',
            ]);
            $admin->forceFill(['email_verified_at' => now(), 'platform_role' => PlatformRole::SuperAdmin])->save();
        }
    }
}
