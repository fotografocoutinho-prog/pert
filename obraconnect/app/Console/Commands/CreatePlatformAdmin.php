<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('obraconnect:create-platform-admin {email} {--name=} {--role=super_admin}')]
#[Description('Cria (ou promove) um utilizador da equipa da plataforma')]
class CreatePlatformAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));
        $role = PlatformRole::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->error('Papel inválido. Use: '.implode(', ', array_column(PlatformRole::cases(), 'value')));

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $name = $this->option('name') ?: $this->ask('Nome');
            $password = $this->secret('Password (mín. 10 caracteres, letras e números)');

            $validator = Validator::make(
                ['email' => $email, 'name' => $name, 'password' => $password],
                ['email' => ['required', 'email', Rule::unique('users')], 'name' => ['required', 'max:255'], 'password' => ['required', Password::defaults()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['platform_role' => $role])->save();

        $this->info("{$email} tem agora o papel de plataforma: {$role->label()}.");
        $this->warn('Na primeira entrada será pedido que ative a autenticação de dois fatores.');

        return self::SUCCESS;
    }
}
