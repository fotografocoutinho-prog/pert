<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Access\PermissionRegistry;
use App\Access\PermissionSynchronizer;
use App\Access\RoleTemplates;
use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly Auditor $auditor,
    ) {}

    /** Quem convida ou gere utilizadores precisa da lista para escolher perfis. */
    public function index(): AnonymousResourceCollection
    {
        abort_unless(Gate::any(['roles.view', 'members.invite', 'members.manage']), 403);

        return RoleResource::collection(
            Role::query()->withCount('memberships')->with('permissions')->orderByDesc('is_locked')->orderBy('id')->get()
        );
    }

    public function store(Request $request): RoleResource
    {
        $this->authorize('roles.manage');

        $data = $this->validateRole($request);
        $this->assertCanGrant($data['permissions']);

        $role = DB::transaction(function () use ($data): Role {
            $role = Role::query()->create([
                'key' => $this->uniqueKey($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $role->permissions()->sync(Permission::query()->whereIn('key', $data['permissions'])->pluck('id'));

            $this->auditor->record('role.created', $role, ['name' => $role->name, 'permissions' => $data['permissions']]);

            return $role;
        });

        return new RoleResource($role->loadCount('memberships'));
    }

    public function update(Request $request, Role $role): RoleResource
    {
        $this->authorize('roles.manage');
        $this->assertEditable($role);

        $data = $this->validateRole($request, $role);
        $before = $this->access->permissionsForRole($role);

        // Só pode mexer nas permissões que também tem: as restantes ficam como estavam.
        $this->assertCanGrant(array_values(array_diff($data['permissions'], $before)));
        $this->assertCanGrant(array_values(array_diff($before, $data['permissions'])));

        DB::transaction(function () use ($role, $data, $before): void {
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            $role->permissions()->sync(Permission::query()->whereIn('key', $data['permissions'])->pluck('id'));

            $this->auditor->record('role.updated', $role, [
                'name' => $role->name,
                'added' => array_values(array_diff($data['permissions'], $before)),
                'removed' => array_values(array_diff($before, $data['permissions'])),
            ]);
        });

        $this->access->flush();

        return new RoleResource($role->refresh()->loadCount('memberships'));
    }

    public function reset(Role $role, PermissionSynchronizer $sync): RoleResource
    {
        $this->authorize('roles.manage');
        $this->assertEditable($role);

        if ($role->template_key === null) {
            throw ApiException::unprocessable('Este perfil foi criado pela empresa e não tem modelo.', 'ROLE_NO_TEMPLATE');
        }

        $this->assertCanGrant(RoleTemplates::permissionsFor($role->template_key));

        $sync->resetRole($role);
        $this->access->flush();
        $this->auditor->record('role.reset', $role, ['template' => $role->template_key]);

        return new RoleResource($role->refresh()->loadCount('memberships'));
    }

    public function destroy(Role $role): Response
    {
        $this->authorize('roles.manage');
        $this->assertEditable($role);

        if ($role->memberships()->exists() || $role->invitations()->pending()->exists()) {
            throw ApiException::conflict('Este perfil está atribuído a utilizadores ou convites. Altere-os primeiro.', 'ROLE_IN_USE');
        }

        $this->auditor->record('role.deleted', $role, ['name' => $role->name]);
        $role->delete();

        return response()->noContent();
    }

    /** @return array{name: string, description?: string|null, permissions: list<string>} */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        $tenantId = $this->tenantContext()->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('tenant_id', $tenantId)->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::keys())],
        ], [], ['name' => 'nome do perfil']);

        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_locked) {
            throw ApiException::forbidden('O perfil de administrador não pode ser alterado.', 'ROLE_LOCKED');
        }
    }

    /** @param  list<string>  $permissions */
    private function assertCanGrant(array $permissions): void
    {
        if (! $this->access->canGrantPermissions($this->membership(), $permissions)) {
            throw ApiException::forbidden('Não pode conceder ou retirar permissões que não tem.', 'ROLE_ESCALATION');
        }
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 50, '') ?: 'perfil';
        $key = $base;
        $i = 2;

        while (Role::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
