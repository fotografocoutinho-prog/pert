<?php

namespace App\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Mantém a tabela `permissions` igual ao catálogo em código e cria perfis de empresas.
 */
class PermissionSynchronizer
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{created: list<string>, removed: list<string>}
     */
    public function sync(): array
    {
        $catalog = PermissionRegistry::all();
        $existing = Permission::query()->pluck('id', 'key');

        $created = [];

        DB::transaction(function () use ($catalog, $existing, &$created): void {
            foreach ($catalog as $key => $description) {
                $permission = Permission::query()->updateOrCreate(
                    ['key' => $key],
                    ['module' => PermissionRegistry::moduleOf($key), 'description' => $description],
                );

                if (! $existing->has($key)) {
                    $created[] = $key;
                    $this->attachToTemplateRoles($permission);
                }
            }
        });

        // Permissões que saíram do catálogo deixam de ter efeito mas não são apagadas
        // automaticamente, para não perder configuração por engano.
        $removed = $existing->keys()->diff(array_keys($catalog))->values()->all();

        return ['created' => $created, 'removed' => $removed];
    }

    /**
     * Uma permissão nova ainda não foi personalizada por ninguém, por isso é
     * seguro juntá-la aos perfis que derivam de um modelo que a inclui.
     */
    private function attachToTemplateRoles(Permission $permission): void
    {
        $templates = collect(RoleTemplates::all())
            ->filter(fn (array $t, string $key) => in_array($permission->key, RoleTemplates::permissionsFor($key), true))
            ->keys();

        if ($templates->isEmpty()) {
            return;
        }

        $this->tenants->runWithoutTenant(function () use ($templates, $permission): void {
            Role::query()->whereIn('template_key', $templates)->pluck('id')
                ->chunk(500)
                ->each(fn ($ids) => $permission->roles()->syncWithoutDetaching($ids->all()));
        });
    }

    /**
     * Copia os perfis-modelo para uma empresa. Devolve os perfis por chave.
     *
     * @return array<string, Role>
     */
    public function createRolesFor(Tenant $tenant): array
    {
        $permissionIds = Permission::query()->pluck('id', 'key');

        return $this->tenants->runAs($tenant, function () use ($permissionIds): array {
            $roles = [];

            foreach (RoleTemplates::all() as $key => $template) {
                $role = new Role([
                    'key' => $key,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'template_key' => $key,
                ]);
                $role->is_locked = $key === Role::ADMIN;
                $role->save();

                $ids = collect(RoleTemplates::permissionsFor($key))
                    ->map(fn (string $p) => $permissionIds[$p] ?? null)
                    ->filter()
                    ->values()
                    ->all();
                $role->permissions()->sync($ids);

                $roles[$key] = $role;
            }

            return $roles;
        });
    }

    public function resetRole(Role $role): void
    {
        if ($role->template_key === null) {
            return;
        }

        $ids = Permission::query()
            ->whereIn('key', RoleTemplates::permissionsFor($role->template_key))
            ->pluck('id');

        $role->permissions()->sync($ids->all());
    }
}
