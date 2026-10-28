<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'description', 'price_monthly_cents', 'price_yearly_cents', 'currency',
    'trial_days', 'limits', 'features', 'is_public', 'is_active', 'sort_order',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'price_monthly_cents' => 'integer',
            'price_yearly_cents' => 'integer',
            'trial_days' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
