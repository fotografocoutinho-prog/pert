<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de subscrição. "activation"/"renewal": a pagar, a plataforma confirma depois de faturar e só aí
 * o período começa. "upgrade": subida de plano a meio do período, já aplicada; fica a diferença a faturar.
 * Como as subscrições, é da relação entre a plataforma e a empresa (lê-se sempre pela empresa).
 */
class SubscriptionOrder extends Model
{
    public const KINDS = ['activation' => 'Ativação', 'renewal' => 'Renovação', 'upgrade' => 'Subida de plano'];

    public const STATUSES = ['pending' => 'Por confirmar', 'confirmed' => 'Confirmado', 'canceled' => 'Cancelado', 'expired' => 'Expirado'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'list_price_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'canceled_at' => 'datetime',
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

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function needsPayment(): bool
    {
        return $this->kind !== 'upgrade';
    }

    public function label(): string
    {
        return 'Pedido n.º '.$this->id;
    }
}
