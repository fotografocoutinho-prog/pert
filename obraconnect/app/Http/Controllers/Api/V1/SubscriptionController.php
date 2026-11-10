<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PlanLimits;
use App\Billing\SubscriptionChanges;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __invoke(Request $request, PlanLimits $limits, SubscriptionChanges $changes): JsonResponse
    {
        $this->authorize('subscription.view');

        $tenant = $this->tenantContext()->tenantOrFail();
        $subscription = $changes->current($tenant);
        $custom = SubscriptionChanges::hasCustomTerms($subscription);

        return response()->json(['data' => [
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'usage' => $limits->summary($tenant),
            'tenant_status' => $tenant->status->value,
            'can_manage' => $request->user()->can('subscription.manage') && ! $custom,
            'custom_terms' => $custom,
            'renewal_opens_at' => $changes->renewalOpensAt($subscription)?->toIso8601String(),
            'payment_instructions' => config('obraconnect.billing.payment_instructions'),
            'orders' => $tenant->subscriptionOrders()->with(['plan', 'coupon'])->latest('id')->limit(20)->get()
                ->map(fn (SubscriptionOrder $o) => SubscriptionChangeController::order($o))->values(),
        ]]);
    }
}
