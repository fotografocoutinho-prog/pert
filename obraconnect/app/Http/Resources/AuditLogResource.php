<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'actor_type' => $this->actor_type,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'email' => $this->actor->email] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'tenant_id' => $this->when($request->is('api/v1/platform/*'), $this->tenant_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
