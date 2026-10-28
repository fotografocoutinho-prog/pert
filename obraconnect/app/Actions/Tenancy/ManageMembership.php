<?php

namespace App\Actions\Tenancy;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alterar perfil/estado de um membro ou removê-lo, garantindo que a empresa
 * nunca fica sem um administrador ativo.
 */
class ManageMembership
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{role_id?: int, status?: string, job_title?: string|null}  $data
     */
    public function update(Membership $actor, Membership $target, array $data): Membership
    {
        return DB::transaction(function () use ($actor, $target, $data): Membership {
            $target = Membership::query()->lockForUpdate()->findOrFail($target->getKey());

            // Ninguém altera um membro com mais permissões do que as suas, nem lhe dá um perfil assim.
            $newRole = isset($data['role_id']) ? Role::query()->findOrFail($data['role_id']) : null;

            if (! $this->access->canAssignRole($actor, $target->role)
                || ($newRole && ! $this->access->canAssignRole($actor, $newRole))) {
                throw ApiException::forbidden('Não pode alterar utilizadores ou perfis com mais permissões do que as suas.', 'ROLE_ESCALATION');
            }

            if (isset($data['status']) && $target->is($actor) && $data['status'] !== MembershipStatus::Active->value) {
                throw ApiException::unprocessable('Não pode suspender a sua própria conta.', 'SELF_SUSPEND');
            }

            $target->fill($data);

            if ($target->isDirty(['role_id', 'status'])) {
                $this->assertAdminRemains($target);
            }

            $target->save();

            $this->auditor->record('membership.updated', $target, $this->auditor->changes($target) + ['user_id' => $target->user_id]);

            return $target->load('role', 'user');
        });
    }

    public function remove(Membership $actor, Membership $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = Membership::query()->lockForUpdate()->findOrFail($target->getKey());

            if (! $this->access->canAssignRole($actor, $target->role)) {
                throw ApiException::forbidden('Não pode remover um utilizador com mais permissões do que as suas.', 'ROLE_ESCALATION');
            }

            // Simula a remoção para confirmar que fica pelo menos um administrador.
            $target->status = MembershipStatus::Suspended;
            $this->assertAdminRemains($target);

            $target->delete();

            User::query()->whereKey($target->user_id)
                ->where('current_tenant_id', $target->tenant_id)
                ->update(['current_tenant_id' => null]);

            $this->auditor->record('membership.removed', $target, ['user_id' => $target->user_id, 'role_id' => $target->role_id]);
        });
    }

    private function assertAdminRemains(Membership $changed): void
    {
        $adminRoleId = (int) Role::query()->where('key', Role::ADMIN)->value('id');

        $stillAdmin = (int) $changed->role_id === $adminRoleId && $changed->status === MembershipStatus::Active;

        if ($stillAdmin) {
            return;
        }

        $otherAdmins = Membership::query()
            ->active()
            ->where('role_id', $adminRoleId)
            ->whereKeyNot($changed->getKey())
            ->count();

        if ($otherAdmins === 0) {
            throw ApiException::unprocessable('A empresa tem de manter pelo menos um administrador ativo.', 'LAST_ADMIN');
        }
    }
}
