<?php

namespace App\Actions\Fortify;

use App\Actions\Tenancy\AcceptInvitation;
use App\Actions\Tenancy\RegisterCompany;
use App\Models\Invitation;
use App\Models\User;
use App\Rules\ValidPortugueseNif;
use App\Support\PortugueseNif;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Registo público. Dois casos:
 * - sem convite: cria a conta e uma empresa nova (com período experimental);
 * - com convite válido: cria a conta e junta-a à empresa que convidou.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly RegisterCompany $registerCompany,
        private readonly AcceptInvitation $acceptInvitation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $hasInvitation = filled($input['invitation_token'] ?? null);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'accept_terms' => ['accepted'],
            'invitation_token' => ['nullable', 'string', 'size:64'],
            'company_name' => [Rule::requiredIf(! $hasInvitation), 'nullable', 'string', 'max:150'],
            'nif' => ['nullable', 'string', 'max:20', new ValidPortugueseNif],
        ], [], [
            'company_name' => 'nome da empresa',
            'accept_terms' => 'termos de utilização',
        ])->validate();

        if ($hasInvitation) {
            $invitation = Invitation::findByToken($validated['invitation_token']);

            if (! $invitation?->isPending() || Str::lower($invitation->email) !== Str::lower($validated['email'])) {
                throw ValidationException::withMessages([
                    'invitation_token' => 'Convite inválido, expirado ou destinado a outro email.',
                ]);
            }
        }

        return DB::transaction(function () use ($validated, $hasInvitation): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
            ]);

            if ($hasInvitation) {
                $this->acceptInvitation->handle($user, $validated['invitation_token']);
            } else {
                $this->registerCompany->handle($user, [
                    'name' => $validated['company_name'],
                    'nif' => filled($validated['nif'] ?? null) ? PortugueseNif::normalize($validated['nif']) : null,
                ]);
            }

            return $user->refresh();
        });
    }
}
