<?php

namespace App\Http\Resources;

use App\Models\TenantExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantExport */
class ExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requested_by' => $this->requestedBy?->name,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'is_downloadable' => $this->isDownloadable(),
            'error' => $this->status->value === 'failed' ? 'A exportação falhou. Tente novamente ou contacte o suporte.' : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
