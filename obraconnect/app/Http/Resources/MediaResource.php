<?php

namespace App\Http\Resources;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Caminhos relativos à instalação: a SPA junta o caminho base (ex.: /obraconnect).
        $base = "/api/v1/projects/{$this->project_id}/media/{$this->id}";
        $ready = $this->status === MediaStatus::Ready;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'caption' => $this->caption,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'width' => $this->width,
            'height' => $this->height,
            'taken_at' => $this->taken_at?->toIso8601String(),
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'urls' => [
                'thumb' => $ready ? "{$base}/thumb" : null,
                'preview' => $ready ? "{$base}/preview" : null,
                'original' => "{$base}/original",
            ],
        ];
    }
}
