<?php

namespace App\Http\Resources;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Membership */
class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'job_title' => $this->job_title,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'two_factor_enabled' => $this->user->hasTwoFactorEnabled(),
                'last_login_at' => $this->user->last_login_at?->toIso8601String(),
            ],
            'role' => [
                'id' => $this->role->id,
                'key' => $this->role->key,
                'name' => $this->role->name,
            ],
            'is_me' => $this->user_id === $request->user()?->id,
        ];
    }
}
