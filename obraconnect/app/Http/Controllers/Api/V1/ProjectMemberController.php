<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Equipa da obra. O perfil na obra decide o que a pessoa faz nela; por omissão é o
 * perfil que tem na empresa. Aplica-se a mesma regra contra escalada de privilégios
 * dos utilizadores: só se atribui (ou retira) um perfil contido nas próprias permissões.
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly AccessResolver $access,
    ) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('projects.view', $project);

        $members = $project->members()
            ->with(['membership.user', 'membership.role', 'role'])
            ->get()
            ->sortBy(fn (ProjectMember $m) => mb_strtolower($m->membership->user->name))
            ->values();

        return ProjectMemberResource::collection($members);
    }

    /**
     * Para o formulário da equipa: quem ainda pode entrar e que perfis quem pede pode atribuir.
     * Não exige members.view nem roles.view: basta poder gerir a equipa desta obra.
     */
    public function options(Project $project): JsonResponse
    {
        $this->authorize('projects.team', $project);

        $actor = $this->membership();
        $inTeam = $project->members()->pluck('membership_id');

        $memberships = Membership::query()->active()
            ->with(['user:id,name,email', 'role:id,name'])
            ->whereNotIn('id', $inTeam)
            ->get()
            ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
            ->map(fn (Membership $m) => [
                'membership_id' => $m->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'company_role' => ['id' => $m->role->id, 'name' => $m->role->name],
            ])
            ->values();

        $roles = Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role) => $actor !== null && $this->access->canAssignRole($actor, $role))
            ->map(fn (Role $role) => ['id' => $role->id, 'key' => $role->key, 'name' => $role->name])
            ->values();

        return response()->json(['data' => ['members' => $memberships, 'roles' => $roles]]);
    }

    public function store(Request $request, Project $project): ProjectMemberResource
    {
        $this->authorize('projects.team', $project);
        $this->assertNotArchived($project);

        $tenantId = $this->tenantContext()->id();
        $data = $request->validate([
            'membership_id' => ['required', 'integer', Rule::exists('memberships', 'id')->where('tenant_id', $tenantId)],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
        ], [], ['membership_id' => 'utilizador', 'role_id' => 'perfil na obra']);

        $membership = Membership::query()->with(['user', 'role'])->findOrFail($data['membership_id']);

        if (! $membership->isActive()) {
            throw ApiException::unprocessable('Este utilizador está suspenso na empresa.', 'MEMBERSHIP_INACTIVE');
        }
        if ($project->members()->where('membership_id', $membership->id)->exists()) {
            throw ApiException::conflict('Esta pessoa já faz parte da equipa da obra.', 'ALREADY_IN_TEAM');
        }

        $role = isset($data['role_id']) ? Role::query()->findOrFail($data['role_id']) : $membership->role;
        $this->assertCanAssign($role, 'Não pode atribuir um perfil com mais permissões do que as suas.');

        $member = $project->members()->create([
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'added_by_user_id' => $request->user()->id,
        ]);
        $this->access->flush();

        $this->auditor->record('project.member_added', $project, ['user' => $membership->user->email, 'role' => $role->name]);

        return new ProjectMemberResource($member->load(['membership.user', 'membership.role', 'role']));
    }

    public function update(Request $request, Project $project, ProjectMember $member): ProjectMemberResource
    {
        $this->authorize('projects.team', $project);
        $this->assertNotArchived($project);

        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', $this->tenantContext()->id())],
        ], [], ['role_id' => 'perfil na obra']);

        $role = Role::query()->findOrFail($data['role_id']);
        $this->assertCanAssign($member->role, 'Não pode alterar quem tem mais permissões do que as suas.');
        $this->assertCanAssign($role, 'Não pode atribuir um perfil com mais permissões do que as suas.');

        $previous = $member->role->name;
        $member->role()->associate($role)->save();
        $this->access->flush();

        $member->load(['membership.user', 'membership.role', 'role']);
        $this->auditor->record('project.member_updated', $project, [
            'user' => $member->membership->user->email,
            'old' => ['role' => $previous],
            'new' => ['role' => $role->name],
        ]);

        return new ProjectMemberResource($member);
    }

    public function destroy(Project $project, ProjectMember $member): Response
    {
        $this->authorize('projects.team', $project);
        $this->assertNotArchived($project);
        $this->assertCanAssign($member->role, 'Não pode retirar da equipa quem tem mais permissões do que as suas.');

        $email = $member->membership->user->email;
        $member->delete();
        $this->access->flush();

        $this->auditor->record('project.member_removed', $project, ['user' => $email]);

        return response()->noContent();
    }

    private function assertCanAssign(Role $role, string $message): void
    {
        $actor = $this->membership();

        if ($actor === null || ! $this->access->canAssignRole($actor, $role)) {
            throw ApiException::forbidden($message, 'ROLE_ESCALATION');
        }
    }

    private function assertNotArchived(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para alterar a equipa.', 'PROJECT_ARCHIVED');
        }
    }
}
