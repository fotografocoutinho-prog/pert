<?php

namespace App\Notifications;

use App\Enums\InspectionStatus;
use App\Mail\ProjectNoticeMail;
use App\Models\Inspection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Emails de aviso das inspeções e ocorrências. Só para pessoas da equipa da obra (ver
 * ProjectAudience) e nunca para quem fez a ação. Vão pela fila (o servidor envia-os no minuto seguinte).
 */
final class Notifier
{
    public function __construct(
        private readonly ProjectAudience $audience,
        private readonly TenantContext $tenants,
    ) {}

    public function inspectionRequested(Project $project, Inspection $inspection, User $actor): void
    {
        $this->send($project, $this->audience->withPermission($project, 'inspections.perform'), $actor,
            "Inspeção pedida: n.º {$inspection->number}",
            'Há uma inspeção para fazer',
            [
                $this->e($actor->name)." pediu a inspeção n.º {$inspection->number}, **".$this->e($inspection->title).'**.',
                $inspection->scheduled_for ? 'Data pretendida: '.$inspection->scheduled_for->timezone('Europe/Lisbon')->format('d/m/Y H:i').'.' : null,
                $inspection->hold_point ? 'É um **ponto de paragem**: os trabalhos estão parados até a inspeção ser aprovada e libertada.' : null,
            ],
            $this->inspectionUrl($project, $inspection), 'Abrir a inspeção');
    }

    public function inspectionSubmitted(Project $project, Inspection $inspection, User $actor): void
    {
        $required = $inspection->approvals_required > 1 ? " São precisas {$inspection->approvals_required} aprovações de pessoas diferentes." : '';

        $this->send($project, $this->audience->withPermission($project, 'inspections.approve'), $actor,
            "Inspeção para decidir: n.º {$inspection->number}",
            'Há uma inspeção para decidir',
            [
                $this->e($actor->name)." submeteu a inspeção n.º {$inspection->number}, **".$this->e($inspection->title).'**, para apreciação.',
                "Índice de evidências: {$inspection->evidence_score}%.".$required,
            ],
            $this->inspectionUrl($project, $inspection), 'Decidir');
    }

    /** Decisão final (aprovada, com reservas, rejeitada) ou devolução. Aprovações parciais não avisam. */
    public function inspectionDecided(Project $project, Inspection $inspection, string $action, User $actor, ?string $reason): void
    {
        if ($inspection->status === InspectionStatus::Submitted) {
            return;
        }
        $returned = $action === 'return';
        $to = $returned ? [$inspection->inspector_user_id] : [$inspection->requested_by_user_id, $inspection->inspector_user_id];

        $this->send($project, $this->audience->teamUsers($project, $to), $actor,
            ($returned ? 'Inspeção devolvida' : "Inspeção {$this->lower($inspection->status->label())}").": n.º {$inspection->number}",
            $returned ? 'A inspeção foi devolvida para completar' : "Inspeção {$this->lower($inspection->status->label())}",
            [
                'A inspeção n.º '.$inspection->number.', **'.$this->e($inspection->title).'**, ficou «'.$inspection->status->label().'» por decisão de '.$this->e($actor->name).'.',
                $reason ? '«'.$this->e($reason).'»' : null,
                ! $returned && $inspection->isHoldingWork() ? 'É um ponto de paragem: os trabalhos continuam parados até ser libertado.' : null,
            ],
            $this->inspectionUrl($project, $inspection), 'Abrir a inspeção');
    }

    public function holdReleased(Project $project, Inspection $inspection, User $actor, ?string $note): void
    {
        $users = $this->audience->teamUsers($project, [$inspection->requested_by_user_id, $inspection->inspector_user_id])
            ->merge($this->audience->siteDirectors($project));

        $this->send($project, $users, $actor,
            "Ponto de paragem libertado: inspeção n.º {$inspection->number}",
            'Os trabalhos podem avançar',
            [
                $this->e($actor->name)." libertou o ponto de paragem da inspeção n.º {$inspection->number}, **".$this->e($inspection->title).'**.',
                $note ? '«'.$this->e($note).'»' : null,
            ],
            $this->inspectionUrl($project, $inspection), 'Abrir a inspeção');
    }

    public function issueAssigned(Project $project, Issue $issue, User $actor): void
    {
        $this->send($project, $this->audience->teamUsers($project, [$issue->assignee_user_id]), $actor,
            "É responsável pela {$issue->reference()}",
            "{$issue->kind->label()} para resolver",
            [
                $this->e($actor->name)." indicou-o como responsável pela {$issue->reference()}, **".$this->e($issue->title).'** (gravidade '.$this->lower($issue->severity->label()).').',
                $issue->due_on ? 'Prazo: '.$issue->due_on->format('d/m/Y').'. Passado o prazo, o diretor de obra é avisado.' : 'Sem prazo definido.',
            ],
            $this->issueUrl($project, $issue), 'Abrir');
    }

    public function issueResolved(Project $project, Issue $issue, User $actor): void
    {
        $users = $this->audience->teamUsers($project, [$issue->created_by_user_id])->merge($this->audience->siteDirectors($project));

        $this->send($project, $users, $actor,
            "{$issue->reference()} resolvida: falta validar",
            "{$issue->kind->label()} resolvida",
            [
                $this->e($actor->name)." marcou a {$issue->reference()}, **".$this->e($issue->title).'**, como resolvida.',
                $issue->resolution_note ? '«'.$this->e($issue->resolution_note).'»' : null,
                'Quem valida pode fechá-la ou reabri-la.',
            ],
            $this->issueUrl($project, $issue), 'Validar');
    }

    public function issueReopened(Project $project, Issue $issue, User $actor, ?string $reason): void
    {
        $this->send($project, $this->audience->teamUsers($project, [$issue->assignee_user_id]), $actor,
            "{$issue->reference()} reaberta",
            "{$issue->kind->label()} reaberta",
            [
                $this->e($actor->name)." reabriu a {$issue->reference()}, **".$this->e($issue->title).'**.',
                $reason ? '«'.$this->e($reason).'»' : null,
            ],
            $this->issueUrl($project, $issue), 'Abrir');
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  list<string|null>  $lines
     */
    private function send(Project $project, Collection $users, User $actor, string $subject, string $heading, array $lines, string $url, string $button): void
    {
        $company = $this->tenants->tenantOrFail()->name;
        $label = "{$project->code} · {$project->name}";

        foreach ($users->unique('id')->reject(fn (User $u) => $u->id === $actor->id) as $user) {
            Mail::to($user->email)->queue(new ProjectNoticeMail($company, $label, $subject, $heading, array_values(array_filter($lines)), $url, $button));
        }
    }

    private function inspectionUrl(Project $project, Inspection $inspection): string
    {
        return url("/app/obras/{$project->id}/inspecoes/{$inspection->id}");
    }

    private function issueUrl(Project $project, Issue $issue): string
    {
        return url("/app/obras/{$project->id}/ocorrencias/{$issue->id}");
    }

    private function e(?string $text): string
    {
        return ProjectNoticeMail::escape($text);
    }

    private function lower(string $label): string
    {
        return mb_strtolower($label);
    }
}
