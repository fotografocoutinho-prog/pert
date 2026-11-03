<?php

namespace App\Tenancy;

use App\Models\AuditLog;
use App\Models\DiaryEntry;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Membership;
use App\Models\Project;
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

        $this->sections['projects'] = fn () => Project::query()->with(['members.membership.user:id,name,email', 'members.role:id,name'])->get()
            ->map(fn (Project $p) => $p->only([
                'code', 'name', 'owner_name', 'address', 'postal_code', 'city', 'contract_value_cents', 'description', 'created_at',
            ]) + [
                'type' => $p->type?->value,
                'status' => $p->status->value,
                'starts_on' => $p->starts_on?->toDateString(),
                'planned_end_on' => $p->planned_end_on?->toDateString(),
                'team' => $p->members->map(fn ($m) => [
                    'name' => $m->membership->user->name,
                    'email' => $m->membership->user->email,
                    'role' => $m->role->name,
                ]),
            ]);

        $this->sections['site_diary'] = fn () => DiaryEntry::query()->with(['project:id,code', 'items', 'createdBy:id,name', 'submittedBy:id,name'])
            ->orderBy('project_id')->orderBy('entry_date')->lazy(500)
            ->map(fn (DiaryEntry $e) => [
                'project' => $e->project->code,
                'date' => $e->entry_date->toDateString(),
                'status' => $e->status->value,
                'weather' => $e->weather,
                'work_summary' => $e->work_summary,
                'notes' => $e->notes,
                'items' => $e->items->map(fn ($i) => [
                    'kind' => $i->kind->value,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit' => $i->unit,
                    'company' => $i->company,
                ]),
                'created_by' => $e->createdBy?->name,
                'submitted_by' => $e->submittedBy?->name,
                'submitted_at' => $e->submitted_at?->toIso8601String(),
            ]);

        // Só a lista (com o SHA-256 de cada original): os ficheiros podem somar gigabytes e não entram no ZIP.
        $this->sections['photos'] = fn () => Media::query()->with(['project:id,code', 'diaryEntry:id,entry_date', 'uploadedBy:id,name'])
            ->orderBy('id')->lazy(500)
            ->map(fn (Media $m) => [
                'project' => $m->project->code,
                'diary_date' => $m->diaryEntry?->entry_date->toDateString(),
                'original_name' => $m->original_name,
                'mime' => $m->mime,
                'size_bytes' => $m->size_bytes,
                'sha256' => $m->sha256,
                'caption' => $m->caption,
                'taken_at' => $m->taken_at?->toIso8601String(),
                'uploaded_by' => $m->uploadedBy?->name,
                'uploaded_at' => $m->created_at?->toIso8601String(),
            ]);

        // Como nas fotografias: a lista e o SHA-256 de cada versão, sem os ficheiros.
        $this->sections['documents'] = fn () => Document::query()
            ->with(['project:id,code', 'folder:id,name', 'versions.uploadedBy:id,name'])
            ->orderBy('id')->lazy(200)
            ->map(fn (Document $d) => [
                'project' => $d->project->code,
                'folder' => $d->folder?->name,
                'title' => $d->title,
                'description' => $d->description,
                'versions' => $d->versions->map(fn ($v) => [
                    'number' => $v->number,
                    'original_name' => $v->original_name,
                    'mime' => $v->mime,
                    'size_bytes' => $v->size_bytes,
                    'sha256' => $v->sha256,
                    'note' => $v->note,
                    'uploaded_by' => $v->uploadedBy?->name,
                    'uploaded_at' => $v->created_at?->toIso8601String(),
                ]),
            ]);

        $this->sections['structure'] = fn () => Project::query()->with(['locations', 'elements'])->orderBy('id')->get()
            ->map(fn (Project $p) => [
                'project' => $p->code,
                'locations' => $p->locations->map(fn ($l) => ['id' => $l->id, 'parent_id' => $l->parent_id, 'kind' => $l->kind->value, 'name' => $l->name]),
                'elements' => $p->elements->map(fn ($e) => [
                    'location_id' => $e->location_id,
                    'type' => $e->element_type->value,
                    'code' => $e->code,
                    'name' => $e->name,
                    'notes' => $e->notes,
                ]),
            ]);

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
