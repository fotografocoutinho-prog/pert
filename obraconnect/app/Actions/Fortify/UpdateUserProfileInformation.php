<?php

namespace App\Actions\Fortify;

use App\Audit\Auditor;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $email = Str::lower($input['email']);

        if ($email !== $user->email && $user instanceof MustVerifyEmail) {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $email,
                'email_verified_at' => null,
            ])->save();

            $user->sendEmailVerificationNotification();
        } else {
            $user->forceFill(['name' => $input['name'], 'email' => $email])->save();
        }

        $this->auditor->record('user.profile_updated', $user, $this->auditor->changes($user), tenantId: false);
    }
}
