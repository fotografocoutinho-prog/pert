<?php

namespace App\Notifications;

use App\Access\AccessResolver;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A quem avisar numa obra: só pessoas ativas da equipa da obra. Quem vê todas as obras pelo perfil
 * da empresa (projects.all) não recebe avisos de obras onde não está — consulta-as na aplicação.
 */
final class ProjectAudience
{
    public function __construct(private readonly AccessResolver $access) {}

    /** @return Collection<int, User> */
    public function withPermission(Project $project, string $permission): Collection
    {
        return $this->team($project)
            ->filter(fn (ProjectMember $m) => $this->access->can($m->membership, $permission, $project))
            ->map(fn (ProjectMember $m) => $m->membership->user)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function siteDirectors(Project $project): Collection
    {
        return $this->team($project)
            ->filter(fn (ProjectMember $m) => $m->role?->template_key === 'diretor_obra')
            ->map(fn (ProjectMember $m) => $m->membership->user)
            ->unique('id')
            ->values();
    }

    /** Os utilizadores indicados que estão ativos na equipa da obra. @param  list<int|null>  $userIds */
    public function teamUsers(Project $project, array $userIds): Collection
    {
        $ids = array_filter($userIds);

        return $this->team($project)
            ->filter(fn (ProjectMember $m) => in_array($m->membership->user_id, $ids, true))
            ->map(fn (ProjectMember $m) => $m->membership->user)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, ProjectMember> */
    private function team(Project $project): Collection
    {
        return $project->members()->with(['membership.user:id,name,email', 'membership.role', 'role'])->get()
            ->filter(fn (ProjectMember $m) => $m->membership->isActive());
    }
}
