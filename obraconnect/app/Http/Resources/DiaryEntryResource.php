<?php

namespace App\Http\Resources;

use App\Models\DiaryEntry;
use App\Models\DiaryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiaryEntry */
class DiaryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'entry_date' => $this->entry_date->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'weather' => $this->weather,
            'work_summary' => $this->work_summary,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (DiaryItem $item) => [
                'id' => $item->id,
                'kind' => $item->kind->value,
                'kind_label' => $item->kind->label(),
                'description' => $item->description,
                'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
                'unit' => $item->unit,
                'company' => $item->company,
                'location_id' => $item->location_id,
                'element_id' => $item->element_id,
            ])),
            'items_count' => $this->whenCounted('items'),
            'photos' => MediaResource::collection($this->whenLoaded('photos')),
            'photos_count' => $this->whenCounted('photos'),
            'workforce_total' => $this->when(isset($this->workforce_total), fn () => (float) $this->workforce_total),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->name),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
