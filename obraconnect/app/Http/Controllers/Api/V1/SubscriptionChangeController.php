<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PaymentDetails;
use App\Billing\SubscriptionChanges;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\SubscriptionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A empresa muda de plano, pede a ativação ou a renovação (com cupão) e usa cupões de período
 * experimental. Funciona também em modo só leitura (é assim que uma empresa expirada volta).
 */
class SubscriptionChangeController extends Controller
{
    public function __construct(private readonly SubscriptionChanges $changes) {}

    /** Pré-visualização: o que acontece, quanto custa e se o plano chega para o que a empresa usa. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('subscription.manage');
        $data = $this->validated($request, withIntent: true);
        $tenant = $this->tenantContext()->tenantOrFail();
        $plan = Plan::query()->findOrFail($data['plan_id']);

        if ($data['intent'] === 'change') {
            $q = $this->changes->planChange($tenant, $plan, $data['billing_cycle']);

            return response()->json(['data' => [
                'intent' => 'change', 'action' => $q['action'], 'plan' => new PlanResource($plan), 'billing_cycle' => $data['billing_cycle'],
                'total_cents' => $q['amount_cents'], 'effective_at' => $q['effective_at']->toIso8601String(),
                'period_ends_at' => $q['period_ends_at']?->toIso8601String(), 'over' => $q['over'], 'allowed' => $q['over'] === [], 'message' => $q['message'],
            ]]);
        }

        $q = $this->changes->orderQuote($tenant, $plan, $data['billing_cycle'], $data['coupon_code'] ?? null);

        return response()->json(['data' => [
            'intent' => 'order', 'action' => $q['kind'], 'plan' => new PlanResource($plan), 'billing_cycle' => $data['billing_cycle'],
            'list_price_cents' => $q['list_price_cents'], 'discount_cents' => $q['discount_cents'], 'total_cents' => $q['total_cents'],
            'coupon' => $q['coupon'] ? ['code' => $q['coupon']->code, 'description' => $q['coupon']->describe()] : null,
            'period_starts_at' => $q['period_starts_at']->toIso8601String(), 'period_ends_at' => $q['period_ends_at']->toIso8601String(),
            'over' => $q['over'], 'allowed' => $q['over'] === [], 'message' => $q['message'],
            'payment_instructions' => PaymentDetails::current()['instructions'],
        ]]);
    }

    public function change(Request $request): JsonResponse
    {
        $this->authorize('subscription.manage');
        $data = $this->validated($request);
        $result = $this->changes->changePlan($this->tenantContext()->tenantOrFail(), Plan::query()->findOrFail($data['plan_id']), $data['billing_cycle'], $request->user());

        return response()->json(['data' => [
            'action' => $result['action'],
            'subscription' => new SubscriptionResource($result['subscription']),
            'order' => $result['order'] ? self::order($result['order']->load('plan')) : null,
        ]]);
    }

    public function cancelScheduled(): JsonResponse
    {
        $this->authorize('subscription.manage');

        return response()->json(['data' => new SubscriptionResource($this->changes->cancelScheduled($this->tenantContext()->tenantOrFail()))]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $this->authorize('subscription.manage');
        $data = $this->validated($request);
        $order = $this->changes->createOrder($this->tenantContext()->tenantOrFail(), Plan::query()->findOrFail($data['plan_id']),
            $data['billing_cycle'], $data['coupon_code'] ?? null, $request->user());

        return response()->json(['data' => self::order($order->load(['plan', 'coupon']))], 201);
    }

    public function cancelOrder(string $order): JsonResponse
    {
        $this->authorize('subscription.manage');
        $found = $this->tenantContext()->tenantOrFail()->subscriptionOrders()->whereKey((int) $order)->firstOrFail();

        return response()->json(['data' => self::order($this->changes->cancelOrder($found, null, byPlatform: false)->load(['plan', 'coupon']))]);
    }

    /** Cupão de dias extra de período experimental. */
    public function redeemCoupon(Request $request): JsonResponse
    {
        $this->authorize('subscription.manage');
        $code = $request->validate(['code' => ['required', 'string', 'max:40']], [], ['code' => 'código'])['code'];

        return response()->json(['data' => new SubscriptionResource($this->changes->extendTrial($this->tenantContext()->tenantOrFail(), $code, $request->user()))]);
    }

    public static function order(SubscriptionOrder $o): array
    {
        return [
            'id' => $o->id,
            'kind' => $o->kind,
            'kind_label' => SubscriptionOrder::KINDS[$o->kind] ?? $o->kind,
            'status' => $o->status,
            'status_label' => SubscriptionOrder::STATUSES[$o->status] ?? $o->status,
            'plan' => $o->relationLoaded('plan') && $o->plan ? ['id' => $o->plan->id, 'code' => $o->plan->code, 'name' => $o->plan->name] : null,
            'billing_cycle' => $o->billing_cycle,
            'list_price_cents' => $o->list_price_cents,
            'discount_cents' => $o->discount_cents,
            'total_cents' => $o->total_cents,
            'currency' => $o->currency,
            'coupon' => $o->relationLoaded('coupon') ? $o->coupon?->code : null,
            'period_starts_at' => $o->period_starts_at?->toIso8601String(),
            'period_ends_at' => $o->period_ends_at?->toIso8601String(),
            'confirmed_at' => $o->confirmed_at?->toIso8601String(),
            'invoice_reference' => $o->invoice_reference,
            'payment_reference' => PaymentDetails::reference($o),
            'canceled_at' => $o->canceled_at?->toIso8601String(),
            'cancel_reason' => $o->cancel_reason,
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    /** @return array{plan_id: int, billing_cycle: string, coupon_code?: ?string, intent?: string} */
    private function validated(Request $request, bool $withIntent = false): array
    {
        return $request->validate(array_filter([
            'intent' => $withIntent ? ['required', Rule::in(['change', 'order'])] : null,
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]), [], ['plan_id' => 'plano', 'billing_cycle' => 'faturação', 'coupon_code' => 'cupão']);
    }
}
