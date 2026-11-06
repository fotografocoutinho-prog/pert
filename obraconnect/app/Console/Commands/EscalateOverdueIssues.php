<?php

namespace App\Console\Commands;

use App\Audit\Auditor;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Mail\IssuesOverdueMail;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Ocorrências com o prazo ultrapassado: avisa o responsável e escala ao diretor de obra (os
 * diretores de obra da equipa; sem nenhum, os administradores da empresa). Cada ocorrência é
 * escalonada uma vez por prazo: mudar o prazo ou reabrir volta a permitir.
 */
#[Signature('obraconnect:escalate-issues')]
#[Description('Escalona as ocorrências e não conformidades com o prazo ultrapassado')]
class EscalateOverdueIssues extends Command
{
    public function handle(TenantContext $tenants, Auditor $auditor): int
    {
        $total = 0;

        Tenant::query()->where('status', TenantStatus::Active->value)->lazyById()->each(function (Tenant $tenant) use ($tenants, $auditor, &$total): void {
            $tenants->runAs($tenant, function () use ($tenant, $auditor, &$total): void {
                $issues = Issue::query()->overdue()->whereNull('escalated_at')
                    ->with(['project:id,code,name', 'assignee:id,name,email'])
                    ->orderBy('project_id')->orderBy('number')
                    ->get();
                if ($issues->isEmpty()) {
                    return;
                }

                /** @var array<int, array{user: User, issues: list<Issue>}> $byRecipient */
                $byRecipient = [];
                foreach ($issues as $issue) {
                    $recipients = $this->recipients($issue);
                    $issue->forceFill(['escalated_at' => now()])->save();
                    IssueEvent::add($issue, 'escalated', null, null, ['notified' => $recipients->pluck('name')->values()->all()]);
                    $auditor->record('issue.escalated', $issue, ['reference' => $issue->reference(), 'notified' => $recipients->count()], actorType: 'system');

                    foreach ($recipients as $user) {
                        $byRecipient[$user->id] ??= ['user' => $user, 'issues' => []];
                        $byRecipient[$user->id]['issues'][] = $issue;
                    }
                    $total++;
                }

                // Um email por pessoa com todas as suas.
                foreach ($byRecipient as ['user' => $user, 'issues' => $list]) {
                    Mail::to($user->email)->queue(new IssuesOverdueMail($tenant->name, collect($list)->map(fn (Issue $i) => [
                        'reference' => $i->reference(),
                        'title' => $i->title,
                        'project' => "{$i->project->code} · {$i->project->name}",
                        'due_on' => $i->due_on->format('d/m/Y'),
                        'assignee' => $i->assignee?->name,
                        'mine' => $i->assignee_user_id === $user->id,
                        'url' => url("/app/obras/{$i->project_id}/ocorrencias/{$i->id}"),
                    ])->all()));
                }
            });
        });

        $this->info("Ocorrências escalonadas: {$total}");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function recipients(Issue $issue): Collection
    {
        $active = fn ($q) => $q->where('status', MembershipStatus::Active->value);
        $users = collect();

        if ($issue->assignee && Membership::query()->tap($active)->where('user_id', $issue->assignee_user_id)->exists()) {
            $users->push($issue->assignee);
        }

        $directors = ProjectMember::query()
            ->where('project_id', $issue->project_id)
            ->whereHas('role', fn ($q) => $q->where('template_key', 'diretor_obra'))
            ->whereHas('membership', $active)
            ->with('membership.user:id,name,email')
            ->get()
            ->map(fn (ProjectMember $m) => $m->membership->user);

        if ($directors->isEmpty()) {
            $directors = Membership::query()->tap($active)
                ->whereHas('role', fn ($q) => $q->where('template_key', 'admin'))
                ->with('user:id,name,email')
                ->get()
                ->map(fn (Membership $m) => $m->user);
        }

        return $users->merge($directors)->unique('id')->values();
    }
}
