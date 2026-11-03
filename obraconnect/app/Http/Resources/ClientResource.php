<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'nif' => $this->nif,
            'country_code' => $this->country_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'contact_name' => $this->contact_name,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'notes' => $this->notes,
            'archived' => $this->isArchived(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
