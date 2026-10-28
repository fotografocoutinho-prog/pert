<?php

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;

/**
 * Decide qual a empresa ativa de um utilizador a partir de dados do servidor.
 * Nunca usa identificadores enviados pelo cliente.
 */
class TenantResolver
{
    public function resolveMembership(User $user): ?Membership
    {
        $membership = null;

        if ($user->current_tenant_id !== null) {
            $membership = $this->activeMemberships($user)
                ->where('tenant_id', $user->current_tenant_id)
                ->first();
        }

        $membership ??= $this->activeMemberships($user)->orderBy('id')->first();

        $tenantId = $membership?->tenant_id;
        if ($user->current_tenant_id !== $tenantId) {
            $user->forceFill(['current_tenant_id' => $tenantId])->saveQuietly();
        }

        return $membership;
    }

    private function activeMemberships(User $user)
    {
        return $user->allMemberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('tenant')
            ->with('tenant');
    }
}
