<?php

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectMember */
class ProjectMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->membership;

        return [
            'id' => $this->id,
            'membership_id' => $this->membership_id,
            'user' => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ],
            'membership_status' => $membership->status->value,
            'job_title' => $membership->job_title,
            'company_role' => ['id' => $membership->role->id, 'name' => $membership->role->name],
            'role' => ['id' => $this->role->id, 'key' => $this->role->key, 'name' => $this->role->name],
            'added_at' => $this->created_at?->toIso8601String(),
            'is_me' => $membership->user_id === $request->user()?->id,
        ];
    }
}
