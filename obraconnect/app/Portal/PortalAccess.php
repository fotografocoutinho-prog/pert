<?php

namespace App\Portal;

use App\Audit\Auditor;
use App\Enums\PartnerKind;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Mail\PortalInvitationMail;
use App\Models\Partner;
use App\Models\PartnerPortalUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Acesso dos subempreiteiros ao portal: convidar, reenviar, revogar e aceitar. Quem aceita fica ligado
 * a um subempreiteiro de uma empresa; não passa a ser membro da empresa.
 */
final class PortalAccess
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function invite(Partner $partner, string $email, string $name, User $actor): PartnerPortalUser
    {
        if ($partner->kind !== PartnerKind::Subcontractor) {
            throw ApiException::unprocessable('O portal é só para subempreiteiros.', 'PORTAL_NOT_SUBCONTRACTOR');
        }
        $email = Str::lower(trim($email));
        $access = PartnerPortalUser::query()->where('partner_id', $partner->id)->where('email', $email)->first();
        if ($access && $access->status() === 'active') {
            throw ApiException::conflict('Esta pessoa já tem acesso ao portal.', 'PORTAL_ALREADY_ACTIVE');
        }

        $access ??= new PartnerPortalUser(['partner_id' => $partner->id, 'email' => $email]);
        $access->fill(['name' => $name, 'invited_by_user_id' => $actor->id, 'revoked_at' => null, 'accepted_at' => null, 'user_id' => null]);
        $this->sendInvitation($access, $actor);
        $this->auditor->record('portal.invited', $access, ['partner' => $partner->name, 'email' => $email]);

        return $access;
    }

    public function resend(PartnerPortalUser $access, User $actor): void
    {
        if (! in_array($access->status(), ['pending', 'expired'], true)) {
            throw ApiException::conflict('Só se reenvia um convite que ainda não foi aceite.', 'PORTAL_NOT_PENDING');
        }
        $this->sendInvitation($access, $actor);
    }

    public function revoke(PartnerPortalUser $access): void
    {
        $access->forceFill(['revoked_at' => now(), 'token_hash' => null])->save();
        $this->auditor->record('portal.revoked', $access, ['email' => $access->email]);
    }

    public function accept(User $user, string $token): PartnerPortalUser
    {
        $access = PartnerPortalUser::findByToken($token);
        if ($access === null || ! $access->isPending()) {
            throw ApiException::unprocessable('Convite inválido, expirado ou já utilizado.', 'PORTAL_INVITATION_INVALID');
        }
        if (Str::lower($user->email) !== $access->email) {
            throw ApiException::forbidden('Este convite foi enviado para outro endereço de email.', 'PORTAL_INVITATION_EMAIL_MISMATCH');
        }

        return $this->tenants->runAs($access->tenant()->firstOrFail(), function () use ($user, $access): PartnerPortalUser {
            return DB::transaction(function () use ($user, $access): PartnerPortalUser {
                $access->forceFill(['user_id' => $user->id, 'accepted_at' => now(), 'token_hash' => null, 'expires_at' => null])->save();
                // O token chegou ao email da pessoa, o que confirma o endereço.
                if ($user->email_verified_at === null) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
                $this->auditor->record('portal.accepted', $access, ['email' => $access->email], actor: $user, actorType: 'user');

                return $access;
            });
        });
    }

    /**
     * Acessos ativos de um utilizador (em todas as empresas), para o portal e o /me.
     *
     * @return Collection<int, PartnerPortalUser>
     */
    public function accessesFor(User $user): Collection
    {
        return PartnerPortalUser::query()->withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)->whereNotNull('accepted_at')->whereNull('revoked_at')
            ->with(['tenant:id,name,status', 'partner' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)->select('id', 'tenant_id', 'name', 'archived_at')])
            ->orderBy('id')->get()
            ->filter(fn (PartnerPortalUser $a) => $a->tenant && $a->tenant->status !== TenantStatus::Suspended && $a->partner && $a->partner->archived_at === null)
            ->values();
    }

    private function sendInvitation(PartnerPortalUser $access, User $actor): void
    {
        $token = Str::random(64);
        $access->forceFill(['token_hash' => PartnerPortalUser::hashToken($token), 'expires_at' => now()->addDays(PartnerPortalUser::INVITATION_DAYS)])->save();
        $tenant = $this->tenants->tenantOrFail();
        $partner = $access->partner()->first();
        Mail::to($access->email)->queue(new PortalInvitationMail($tenant->name, $partner->name, $actor->name, $token, $access->expires_at));
    }
}
