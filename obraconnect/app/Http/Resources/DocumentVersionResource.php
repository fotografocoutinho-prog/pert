<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentVersion */
class DocumentVersionResource extends JsonResource
{
    /** Tipos que o browser mostra sem risco; o resto é sempre descarregado. */
    public const INLINE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function toArray(Request $request): array
    {
        $document = $this->document_id;
        $projectId = $this->relationLoaded('document') ? $this->document->project_id : $request->route('project')?->id;
        $base = "/api/v1/projects/{$projectId}/documents/{$document}/versions/{$this->id}/download";

        return [
            'id' => $this->id,
            'number' => $this->number,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'note' => $this->note,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'downloads_count' => $this->whenCounted('downloads'),
            'urls' => [
                'open' => in_array($this->mime, self::INLINE_TYPES, true) ? $base : null,
                'download' => "{$base}?download=1",
            ],
        ];
    }
}
