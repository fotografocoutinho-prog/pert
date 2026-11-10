<?php

namespace App\Billing;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\SubscriptionOrder;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Validação e uso dos cupões. Cada empresa usa cada cupão uma vez; o uso fica reservado logo no pedido
 * (conta para o limite) e liberta-se se o pedido for cancelado ou caducar.
 */
final class Coupons
{
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** Cupão de desconto para um pedido de ativação ou renovação. */
    public function forOrder(string $code, Tenant $tenant, Plan $plan, string $cycle, CarbonInterface $now): Coupon
    {
        $coupon = $this->usable($code, $tenant, $now);
        if ($coupon->isTrialExtension()) {
            throw ApiException::unprocessable('Este cupão dá dias de período experimental: use-o em «Tem um código?».', 'COUPON_NOT_APPLICABLE');
        }
        if (! empty($coupon->plan_ids) && ! in_array($plan->id, array_map('intval', $coupon->plan_ids), true)) {
            throw ApiException::unprocessable("Este cupão não serve para o plano {$plan->name}.", 'COUPON_NOT_FOR_PLAN');
        }
        if (! empty($coupon->billing_cycles) && ! in_array($cycle, $coupon->billing_cycles, true)) {
            $only = implode(' ou ', array_map(fn ($c) => $c === 'yearly' ? 'anual' : 'mensal', $coupon->billing_cycles));
            throw ApiException::unprocessable("Este cupão só serve para a faturação {$only}.", 'COUPON_NOT_FOR_CYCLE');
        }

        return $coupon;
    }

    /** Cupão que prolonga o período experimental. */
    public function forTrial(string $code, Tenant $tenant, CarbonInterface $now): Coupon
    {
        $coupon = $this->usable($code, $tenant, $now);
        if (! $coupon->isTrialExtension()) {
            throw ApiException::unprocessable('Este cupão dá desconto: use-o ao pedir a ativação ou a renovação.', 'COUPON_NOT_APPLICABLE');
        }

        return $coupon;
    }

    /** Reserva o uso (dentro de uma transação): volta a confirmar o limite com o cupão bloqueado. */
    public function redeem(Coupon $coupon, Tenant $tenant, ?SubscriptionOrder $order, ?User $user, int $discountCents = 0, ?int $trialDays = null): CouponRedemption
    {
        $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
        $this->assertNotUsed($locked, $tenant);
        $this->assertNotExhausted($locked);

        return CouponRedemption::query()->create([
            'coupon_id' => $locked->id,
            'tenant_id' => $tenant->id,
            'subscription_order_id' => $order?->id,
            'user_id' => $user?->id,
            'discount_cents' => $discountCents,
            'trial_days' => $trialDays,
        ]);
    }

    /** Pedido cancelado ou caducado: o cupão volta a ficar livre para a empresa. */
    public function release(SubscriptionOrder $order): void
    {
        CouponRedemption::query()->where('subscription_order_id', $order->id)->delete();
    }

    private function usable(string $code, Tenant $tenant, CarbonInterface $now): Coupon
    {
        $coupon = Coupon::query()->where('code', self::normalize($code))->first();
        if ($coupon === null || ! $coupon->is_active) {
            throw ApiException::unprocessable('Este código não existe ou já não está ativo.', 'COUPON_INVALID');
        }
        if ($coupon->valid_from !== null && $coupon->valid_from->gt($now)) {
            throw ApiException::unprocessable('Este cupão só vale a partir de '.$coupon->valid_from->timezone('Europe/Lisbon')->format('d/m/Y').'.', 'COUPON_NOT_STARTED');
        }
        if ($coupon->valid_until !== null && $coupon->valid_until->lt($now)) {
            throw ApiException::unprocessable('Este cupão já expirou.', 'COUPON_EXPIRED');
        }
        $this->assertNotUsed($coupon, $tenant);
        $this->assertNotExhausted($coupon);

        return $coupon;
    }

    private function assertNotUsed(Coupon $coupon, Tenant $tenant): void
    {
        if ($coupon->redemptions()->where('tenant_id', $tenant->id)->exists()) {
            throw ApiException::unprocessable('A empresa já usou este cupão.', 'COUPON_ALREADY_USED');
        }
    }

    private function assertNotExhausted(Coupon $coupon): void
    {
        if ($coupon->max_redemptions !== null && $coupon->redemptions()->count() >= $coupon->max_redemptions) {
            throw ApiException::unprocessable('Este cupão já foi usado o número máximo de vezes.', 'COUPON_EXHAUSTED');
        }
    }
}
