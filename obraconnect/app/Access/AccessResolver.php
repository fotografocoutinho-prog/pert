<?php

namespace App\Access;

use App\Models\Membership;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve as permissões efetivas de uma associação utilizador/empresa.
 * Registado como "scoped": a cache vive apenas durante o pedido.
 */
class AccessResolver
{
    /** @var array<int, list<string>> role_id => chaves */
    private array $cache = [];

    /** @return list<string> */
    public function permissionsFor(Membership $membership): array
    {
        if (! $membership->isActive()) {
            return [];
        }

        return $this->permissionsForRole($membership->role);
    }

    /** @return list<string> */
    public function permissionsForRole(Role $role): array
    {
        if ($role->is_locked && $role->isAdmin()) {
            return PermissionRegistry::keys();
        }

        return $this->cache[$role->getKey()] ??= $role->permissions()->pluck('key')->all();
    }

    /**
     * O argumento $project fica reservado para permissões por obra (fase 2, tabela
     * project_members). Na fase 1 as permissões aplicam-se à empresa inteira.
     */
    public function can(Membership $membership, string $permission, ?Model $project = null): bool
    {
        return in_array($permission, $this->permissionsFor($membership), true);
    }

    /**
     * Impede escalada de privilégios: só se pode atribuir um perfil cujas
     * permissões estejam todas contidas nas permissões de quem atribui.
     */
    public function canAssignRole(Membership $actor, Role $role): bool
    {
        $mine = $this->permissionsFor($actor);

        return array_diff($this->permissionsForRole($role), $mine) === [];
    }

    /** @param  list<string>  $permissions */
    public function canGrantPermissions(Membership $actor, array $permissions): bool
    {
        return array_diff($permissions, $this->permissionsFor($actor)) === [];
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
