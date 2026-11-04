<?php

namespace App\Http\Resources;

use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Inspections\ChecklistItems;
use App\Inspections\EvidenceIndex;
use App\Inspections\InspectionHistory;
use App\Inspections\InspectionWorkflow;
use App\Models\Inspection;
use App\Models\InspectionEvent;
use App\Models\InspectionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Inspeção. Na lista vêm os dados de cabeçalho e contagens; na ficha (com pontos e histórico
 * carregados) vêm também as evidências, o índice, a verificação da cadeia e o que quem pede
 * pode fazer a seguir.
 *
 * @mixin Inspection
 */
class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('items') && $this->relationLoaded('events');

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'number' => $this->number,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remote' => $this->remote,
            'location_id' => $this->location_id,
            'element_id' => $this->element_id,
            'specialty_id' => $this->specialty_id,
            'specialty' => $this->whenLoaded('specialty', fn () => $this->specialty ? ['id' => $this->specialty->id, 'code' => $this->specialty->code, 'name' => $this->specialty->name] : null),
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['id' => $this->template->id, 'name' => $this->template->name, 'version' => $this->template_version] : null),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'evidence_score' => $this->evidence_score,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector?->name),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'reinspection_of' => $this->whenLoaded('reinspectionOf', fn () => $this->reinspectionOf ? ['id' => $this->reinspectionOf->id, 'number' => $this->reinspectionOf->number] : null),
            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'answered_count' => $this->when(isset($this->answered_count), fn () => (int) $this->answered_count),
            'nok_count' => $this->when(isset($this->nok_count), fn () => (int) $this->nok_count),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($detail, fn () => $this->detail()),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        $broken = InspectionHistory::firstBroken($this->events);

        return [
            'description' => $this->description,
            'created_by' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            'items' => $this->items->values()->map(fn (InspectionItem $item, int $i) => ChecklistItems::present($item) + [
                'number' => $i + 1,
                'result' => $item->result?->value,
                'result_label' => $item->result?->label(),
                'measure_value' => $item->measure_value === null ? null : (float) $item->measure_value,
                'out_of_tolerance' => $item->isOutOfTolerance(),
                'notes' => $item->notes,
                'answered_by' => $item->relationLoaded('answeredBy') ? $item->answeredBy?->name : null,
                'answered_at' => $item->answered_at?->toIso8601String(),
                'photos' => MediaResource::collection($item->photos)->resolve(),
            ])->all(),
            'reinspections' => $this->relationLoaded('reinspections')
                ? $this->reinspections->map(fn (Inspection $r) => ['id' => $r->id, 'number' => $r->number, 'status_label' => $r->status->label()])->all()
                : [],
            'evidence' => EvidenceIndex::evaluate($this->resource),
            'events' => $this->events->map(fn (InspectionEvent $e) => [
                'sequence' => $e->sequence,
                'action' => $e->action,
                'label' => InspectionWorkflow::eventLabel($e->action),
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'user_name' => $e->user_name,
                'reason' => $e->reason,
                'scheduled_for' => $e->payloadData()['scheduled_for'] ?? null,
                'evidence_score' => $e->payloadData()['evidence_score'] ?? null,
                'hash' => $e->hash,
                'created_at' => $e->created_at->toIso8601String(),
            ])->all(),
            'chain' => [
                'valid' => $broken === null,
                'broken_at' => $broken,
                'last_hash' => $this->events->last()?->hash,
            ],
            'can' => $this->viewerCan(),
        ];
    }

    /**
     * O que quem pede pode fazer agora (a interface só mostra estes botões; o servidor volta a
     * verificar tudo em cada pedido).
     *
     * @return array<string, mixed>
     */
    private function viewerCan(): array
    {
        $project = $this->project;
        $open = ! $project->isArchived();
        $has = fn (array $permissions) => $open && Gate::any($permissions, $project);

        $actions = [];
        foreach (InspectionWorkflow::actions() as $key => $action) {
            if (in_array($this->status, $action['from'], true) && $has($action['permissions'])) {
                $actions[] = ['key' => $key, 'label' => $action['label'], 'reason' => $action['reason']];
            }
        }

        return [
            'edit' => $this->status === InspectionStatus::Draft && $has(['inspections.request']),
            'fill' => $this->status === InspectionStatus::InProgress && $has(['inspections.perform']),
            'reinspect' => in_array($this->status, [InspectionStatus::Rejected, InspectionStatus::ApprovedWithReservations], true) && $has(['inspections.request']),
            'actions' => $actions,
            'has_nok' => $this->items->contains(fn (InspectionItem $item) => $item->result === InspectionResult::Nok),
        ];
    }
}
