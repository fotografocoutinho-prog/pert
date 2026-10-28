<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relação comercial entre a plataforma e a empresa. Gerida pelo backoffice;
 * a empresa só a lê através de Tenant::currentSubscription().
 */
#[Fillable([
    'plan_id', 'status', 'billing_cycle', 'trial_ends_at', 'current_period_starts_at',
    'current_period_ends_at', 'canceled_at', 'ends_at', 'custom_limits', 'provider',
    'provider_reference', 'notes',
])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
            'custom_limits' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
