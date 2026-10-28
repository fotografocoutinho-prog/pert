<?php

namespace App\Actions\Tenancy;

use App\Audit\Auditor;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptInvitation
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function handle(User $user, string $token): Membership
    {
        $invitation = Invitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw ApiException::unprocessable('Convite inválido, expirado ou já utilizado.', 'INVITATION_INVALID');
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw ApiException::forbidden('Este convite foi enviado para outro endereço de email.', 'INVITATION_EMAIL_MISMATCH');
        }

        $tenant = $invitation->tenant()->firstOrFail();

        return $this->tenants->runAs($tenant, function () use ($user, $invitation, $tenant): Membership {
            return DB::transaction(function () use ($user, $invitation, $tenant): Membership {
                $membership = Membership::query()->firstOrNew(['user_id' => $user->getKey()]);

                if ($membership->exists && $membership->status === MembershipStatus::Active) {
                    throw ApiException::conflict('Já pertence a esta empresa.', 'ALREADY_MEMBER');
                }

                $membership->fill([
                    'role_id' => $invitation->role_id,
                    'status' => MembershipStatus::Active,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'joined_at' => now(),
                ])->save();

                $invitation->forceFill([
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->getKey(),
                ])->save();

                // O token chegou ao email do utilizador, o que confirma o endereço.
                if ($user->email_verified_at === null) {
                    $user->forceFill(['email_verified_at' => now()]);
                }
                $user->forceFill(['current_tenant_id' => $tenant->getKey()])->save();

                $this->auditor->record('invitation.accepted', $invitation, ['email' => $invitation->email], actor: $user, actorType: 'user');

                return $membership;
            });
        });
    }
}
