<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'folder_id' => $this->folder_id,
            'location_id' => $this->location_id,
            'element_id' => $this->element_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            // Quem enviou pode editar e juntar versões com documents.upload; os outros precisam de documents.manage.
            'created_by_me' => (int) $this->created_by_user_id === (int) $request->user()?->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'versions_count' => $this->whenCounted('versions'),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? new DocumentVersionResource($this->currentVersion) : null),
            'versions' => DocumentVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
