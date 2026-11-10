<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cupão da plataforma: percentagem ou valor de desconto num pedido de ativação/renovação, ou dias extra
 * de período experimental (só um dos três).
 */
class Coupon extends Model
{
    protected $fillable = [
        'code', 'description', 'percent_off', 'amount_off_cents', 'trial_extension_days', 'plan_ids',
        'billing_cycles', 'max_redemptions', 'valid_from', 'valid_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off_cents' => 'integer',
            'trial_extension_days' => 'integer',
            'plan_ids' => 'array',
            'billing_cycles' => 'array',
            'max_redemptions' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isTrialExtension(): bool
    {
        return (int) $this->trial_extension_days > 0;
    }

    /** Desconto sobre um preço (cêntimos), nunca acima do preço. */
    public function discountFor(int $priceCents): int
    {
        if ($this->percent_off) {
            return min($priceCents, intdiv($priceCents * $this->percent_off + 50, 100));
        }

        return min($priceCents, (int) $this->amount_off_cents);
    }

    public function describe(): string
    {
        if ($this->isTrialExtension()) {
            return "+{$this->trial_extension_days} dias de período experimental";
        }
        if ($this->percent_off) {
            return "{$this->percent_off} % de desconto";
        }

        return number_format(((int) $this->amount_off_cents) / 100, 2, ',', ' ').' € de desconto';
    }
}
