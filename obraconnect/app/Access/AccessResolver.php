<?php

namespace App\Access;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve as permissões efetivas de uma associação utilizador/empresa.
 * Registado como "scoped": a cache vive apenas durante o pedido.
 *
 * Permissões de obra (PermissionRegistry::isProjectScoped) numa obra concreta:
 * - perfil da empresa, se este tiver projects.all (vale em todas as obras);
 * - mais o perfil que a pessoa tem na equipa dessa obra, se estiver na equipa.
 */
class AccessResolver
{
    /** @var array<int, list<string>> role_id => chaves */
    private array $cache = [];

    /** @var array<int, Role|null> */
    private array $roles = [];

    /** @var array<int, array<int, int>> membership_id => [project_id => role_id] */
    private array $teams = [];

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
     * Sem obra, uma permissão de obra responde "tem-na em alguma obra?" pelo perfil da
     * empresa (serve para mostrar o menu); a decisão real é sempre feita com a obra.
     */
    public function can(Membership $membership, string $permission, ?Model $project = null): bool
    {
        if ($project instanceof Project && PermissionRegistry::isProjectScoped($permission)) {
            return in_array($permission, $this->permissionsForProject($membership, $project), true);
        }

        return in_array($permission, $this->permissionsFor($membership), true);
    }

    /** @return list<string> só permissões de obra */
    public function permissionsForProject(Membership $membership, Project $project): array
    {
        if (! $membership->isActive()) {
            return [];
        }

        $company = $this->permissionsFor($membership);
        $permissions = in_array(PermissionRegistry::ALL_PROJECTS, $company, true) ? $company : [];

        $teamRoleId = $this->teamRoleIds($membership)[$project->getKey()] ?? null;
        if ($teamRoleId !== null && ($role = $this->role($teamRoleId)) !== null) {
            $permissions = array_merge($permissions, $this->permissionsForRole($role));
        }

        return array_values(array_unique(array_filter($permissions, PermissionRegistry::isProjectScoped(...))));
    }

    /**
     * Obras que a associação pode ver: null = todas.
     *
     * @return list<int>|null
     */
    public function visibleProjectIds(Membership $membership): ?array
    {
        if (! $membership->isActive()) {
            return [];
        }

        $company = $this->permissionsFor($membership);
        if (in_array(PermissionRegistry::ALL_PROJECTS, $company, true) && in_array('projects.view', $company, true)) {
            return null;
        }

        $visible = [];
        foreach ($this->teamRoleIds($membership) as $projectId => $roleId) {
            $role = $this->role($roleId);
            if ($role !== null && in_array('projects.view', $this->permissionsForRole($role), true)) {
                $visible[] = $projectId;
            }
        }

        return $visible;
    }

    /** @return array<int, int> project_id => role_id */
    public function teamRoleIds(Membership $membership): array
    {
        return $this->teams[$membership->getKey()] ??= ProjectMember::query()
            ->where('membership_id', $membership->getKey())
            ->pluck('role_id', 'project_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function role(int $id): ?Role
    {
        return array_key_exists($id, $this->roles) ? $this->roles[$id] : ($this->roles[$id] = Role::query()->find($id));
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
        $this->roles = [];
        $this->teams = [];
    }
}
