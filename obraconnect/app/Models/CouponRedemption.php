<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uso de um cupão por uma empresa (num pedido ou para prolongar o período experimental). */
class CouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'tenant_id', 'subscription_order_id', 'user_id', 'discount_cents', 'trial_days'];

    protected function casts(): array
    {
        return ['discount_cents' => 'integer', 'trial_days' => 'integer'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
