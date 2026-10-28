<?php

namespace App\Actions\Tenancy;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Exceptions\ApiException;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteMember
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AccessResolver $access,
        private readonly PlanLimits $limits,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Membership $actor, string $email, Role $role): Invitation
    {
        $tenant = $this->tenants->tenantOrFail();
        $email = Str::lower(trim($email));

        if (! $this->access->canAssignRole($actor, $role)) {
            throw ApiException::forbidden('Não pode atribuir um perfil com mais permissões do que as suas.', 'ROLE_ESCALATION');
        }

        $alreadyMember = Membership::query()
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ApiException::conflict('Este utilizador já pertence à empresa.', 'ALREADY_MEMBER');
        }

        if (Invitation::query()->pending()->where('email', $email)->exists()) {
            throw ApiException::conflict('Já existe um convite pendente para este email.', 'INVITATION_PENDING');
        }

        $this->limits->assertCanAdd($tenant, 'max_users');

        [$invitation, $token] = DB::transaction(function () use ($actor, $email, $role): array {
            $token = Str::random(64);

            $invitation = Invitation::query()->create([
                'email' => $email,
                'role_id' => $role->getKey(),
                'token_hash' => Invitation::hashToken($token),
                'invited_by_user_id' => $actor->user_id,
                'expires_at' => now()->addDays(config('obraconnect.invitations.expires_days')),
            ]);

            $this->auditor->record('invitation.created', $invitation, ['email' => $email, 'role' => $role->key]);

            return [$invitation, $token];
        });

        Mail::to($email)->queue(new InvitationMail($tenant->name, $role->name, $actor->user->name, $token, $invitation->expires_at));

        return $invitation;
    }

    public function resend(Invitation $invitation, Membership $actor): Invitation
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ApiException::unprocessable('Este convite já não está ativo.', 'INVITATION_INACTIVE');
        }

        $token = Str::random(64);
        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(config('obraconnect.invitations.expires_days')),
        ])->save();

        $this->auditor->record('invitation.resent', $invitation, ['email' => $invitation->email]);

        Mail::to($invitation->email)->queue(new InvitationMail(
            $this->tenants->tenantOrFail()->name,
            $invitation->role->name,
            $actor->user->name,
            $token,
            $invitation->expires_at,
        ));

        return $invitation;
    }
}
