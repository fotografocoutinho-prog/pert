<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PlanLimits;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function __invoke(PlanLimits $limits): JsonResponse
    {
        $this->authorize('subscription.view');

        $tenant = $this->tenantContext()->tenantOrFail();
        $subscription = $limits->subscription($tenant);

        return response()->json(['data' => [
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'usage' => $limits->summary($tenant),
            'tenant_status' => $tenant->status->value,
        ]]);
    }
}
