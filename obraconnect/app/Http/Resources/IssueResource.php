<?php

namespace App\Http\Resources;

use App\Issues\IssueWorkflow;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Ocorrência ou não conformidade. Na ficha (historial carregado) vêm também as fotografias, o
 * historial e o que quem pede pode fazer.
 *
 * @mixin Issue
 */
class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'number' => $this->number,
            'reference' => $this->reference(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'title' => $this->title,
            'location_id' => $this->location_id,
            'element_id' => $this->element_id,
            'specialty_id' => $this->specialty_id,
            'specialty' => $this->whenLoaded('specialty', fn () => $this->specialty ? ['id' => $this->specialty->id, 'code' => $this->specialty->code, 'name' => $this->specialty->name] : null),
            'assignee_user_id' => $this->assignee_user_id,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'due_on' => $this->due_on?->toDateString(),
            'overdue' => $this->isOverdue(),
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection ? ['id' => $this->inspection->id, 'number' => $this->inspection->number, 'title' => $this->inspection->title] : null),
            'photos_count' => $this->when(isset($this->photos_count), fn () => (int) $this->photos_count),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($this->relationLoaded('events'), fn () => $this->detail($request)),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Request $request): array
    {
        $userId = $request->user()?->id;
        $can = $this->viewerCan($userId);

        return [
            'description' => $this->description,
            'inspection_item_id' => $this->inspection_item_id,
            // Número do ponto na inspeção (a ordem da lista).
            'inspection_item_number' => $this->inspection_id && $this->inspection_item_id
                ? $this->inspection->items()->pluck('id')->search($this->inspection_item_id) + 1
                : null,
            'resolution_note' => $this->resolution_note,
            'resolved_by' => $this->resolvedBy?->name,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_by' => $this->closedBy?->name,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_by' => $this->createdBy?->name,
            'photos' => $this->photos->map(fn (Media $photo) => (new MediaResource($photo))->resolve() + [
                'can_remove' => $can['photos'] && ($can['validate'] || $photo->uploaded_by_user_id === $userId),
            ])->all(),
            'events' => $this->events->map(fn (IssueEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'label' => IssueEvent::LABELS[$e->type] ?? $e->type,
                'user_name' => $e->user_name,
                'body' => $e->body,
                'payload' => $e->payload,
                'created_at' => $e->created_at->toIso8601String(),
            ])->all(),
            'can' => $can,
        ];
    }

    /** @return array<string, mixed> */
    private function viewerCan(?int $userId): array
    {
        $project = $this->project;
        $open = ! $project->isArchived();
        $allows = fn (string $permission) => $open && Gate::allows($permission, $project);
        $validate = $allows('issues.validate');
        // Quem resolve: o responsável, quem a tiver sem responsável, ou quem também valida.
        $mayResolve = $allows('issues.resolve') && ($this->assignee_user_id === null || $this->assignee_user_id === $userId || $validate);
        $finished = $this->status->isFinished();

        $actions = [];
        foreach (IssueWorkflow::actions() as $key => $action) {
            $allowed = $action['permission'] === 'issues.resolve' ? $mayResolve : $allows($action['permission']);
            if ($allowed && in_array($this->status, $action['from'], true)) {
                $actions[] = ['key' => $key, 'label' => $action['label'], 'reason' => $action['reason']];
            }
        }

        $participate = $open && Gate::any(IssueWorkflow::PARTICIPATE, $project);

        return [
            'edit' => ! $finished && ($validate || ($allows('issues.create') && $this->created_by_user_id === $userId)),
            'validate' => $validate,
            'comment' => $participate,
            'photos' => $participate && ! $finished,
            'actions' => $actions,
        ];
    }
}
