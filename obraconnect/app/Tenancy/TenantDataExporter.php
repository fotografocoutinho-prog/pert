<?php

namespace App\Tenancy;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use Closure;
use RuntimeException;
use ZipArchive;

/**
 * Gera um ZIP com os dados da empresa ativa em JSON. Cada módulo regista a sua
 * secção (fase 2: obras, documentos, etc.). Corre sempre dentro do contexto da
 * empresa, por isso as consultas ficam filtradas pelo TenantScope.
 */
class TenantDataExporter
{
    /** @var array<string, Closure(Tenant): iterable<mixed>> */
    private array $sections = [];

    public function __construct(private readonly TenantContext $tenants)
    {
        $this->sections['company'] = fn (Tenant $t) => [$t->only([
            'id', 'name', 'legal_name', 'nif', 'email', 'phone', 'website', 'address_line1', 'address_line2',
            'postal_code', 'city', 'country_code', 'status', 'settings', 'created_at',
        ])];

        $this->sections['members'] = fn () => Membership::query()->with(['user:id,name,email', 'role:id,key,name'])->get()
            ->map(fn (Membership $m) => [
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->name,
                'status' => $m->status->value,
                'job_title' => $m->job_title,
                'joined_at' => $m->joined_at?->toIso8601String(),
            ]);

        $this->sections['roles'] = fn () => Role::query()->with('permissions:id,key')->get()
            ->map(fn (Role $r) => [
                'key' => $r->key,
                'name' => $r->name,
                'description' => $r->description,
                'permissions' => $r->permissions->pluck('key'),
            ]);

        $this->sections['invitations'] = fn () => Invitation::query()->with('role:id,name')->get()
            ->map(fn (Invitation $i) => [
                'email' => $i->email,
                'role' => $i->role->name,
                'status' => $i->status(),
                'created_at' => $i->created_at?->toIso8601String(),
            ]);

        $this->sections['subscriptions'] = fn (Tenant $t) => $t->subscriptions()->with('plan:id,code,name')->get()
            ->map(fn ($s) => [
                'plan' => $s->plan->name,
                'status' => $s->status->value,
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                'current_period_ends_at' => $s->current_period_ends_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        $this->sections['support_access_grants'] = fn () => SupportAccessGrant::query()->get()
            ->map(fn (SupportAccessGrant $g) => $g->only(['reason', 'access_level', 'expires_at', 'revoked_at', 'last_used_at', 'created_at']));

        $this->sections['audit_logs'] = fn (Tenant $t) => AuditLog::query()->forTenant($t->id)->orderBy('id')->lazy(1000)
            ->map(fn (AuditLog $log) => $log->only(['event', 'actor_type', 'actor_user_id', 'subject_type', 'subject_id', 'properties', 'ip_address', 'created_at']));
    }

    public function registerSection(string $name, Closure $section): void
    {
        $this->sections[$name] = $section;
    }

    /**
     * Escreve o ZIP num ficheiro temporário e devolve o caminho local.
     */
    public function exportToTemporaryFile(): string
    {
        $tenant = $this->tenants->tenantOrFail();
        $path = tempnam(sys_get_temp_dir(), 'obra-export-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro ZIP.');
        }

        $manifest = [
            'generator' => 'ObraConnect '.config('obraconnect.version'),
            'tenant_id' => $tenant->id,
            'generated_at' => now()->toIso8601String(),
            'files' => [],
        ];

        foreach ($this->sections as $name => $section) {
            $data = collect($section($tenant))->values()->all();
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $zip->addFromString("{$name}.json", $json);
            $manifest['files'][] = ['name' => "{$name}.json", 'records' => count($data), 'sha256' => hash('sha256', $json)];
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }
}
