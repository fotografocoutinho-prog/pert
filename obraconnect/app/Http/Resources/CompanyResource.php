<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'nif' => $this->nif,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'has_logo' => $this->logo_path !== null,
            'logo_url' => $this->logo_path ? route('api.v1.company.logo', ['v' => substr(md5($this->logo_path), 0, 8)]) : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'settings' => [
                'timezone' => $this->setting('timezone', 'Europe/Lisbon'),
                'require_mfa' => $this->requiresMfa(),
            ],
            'storage_used_bytes' => $this->storage_used_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
