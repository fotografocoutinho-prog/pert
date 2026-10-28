<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly_cents' => $this->price_monthly_cents,
            'price_yearly_cents' => $this->price_yearly_cents,
            'currency' => $this->currency,
            'prices_exclude_vat' => true,
            'trial_days' => $this->trial_days,
            'limits' => $this->limits,
            'features' => $this->features,
            'is_public' => $this->when($request->is('api/v1/platform/*'), $this->is_public),
            'is_active' => $this->when($request->is('api/v1/platform/*'), $this->is_active),
            'sort_order' => $this->sort_order,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
        ];
    }
}
