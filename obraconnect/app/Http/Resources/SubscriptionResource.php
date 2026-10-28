<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'billing_cycle' => $this->billing_cycle,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_starts_at' => $this->current_period_starts_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'custom_limits' => $this->when($request->is('api/v1/platform/*'), $this->custom_limits),
            'provider' => $this->when($request->is('api/v1/platform/*'), $this->provider),
            'notes' => $this->when($request->is('api/v1/platform/*'), $this->notes),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
