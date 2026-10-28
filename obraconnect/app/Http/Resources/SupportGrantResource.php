<?php

namespace App\Http\Resources;

use App\Models\SupportAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupportAccessGrant */
class SupportGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'access_level' => $this->access_level,
            'is_active' => $this->isActive(),
            'granted_by' => $this->grantedBy?->name,
            'expires_at' => $this->expires_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_by' => $this->lastUsedBy?->name,
            'tenant' => $this->when(
                $request->is('api/v1/platform/*'),
                fn () => ['id' => $this->tenant_id, 'name' => $this->tenant?->name],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
