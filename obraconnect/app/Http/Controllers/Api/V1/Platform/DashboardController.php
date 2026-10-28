<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores comerciais agregados. Não expõe dados internos das empresas.
 */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tenantsByStatus = Tenant::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // Subscrição atual = a mais recente de cada empresa.
        $latestIds = Subscription::query()->select(DB::raw('max(id)'))->groupBy('tenant_id');
        $current = Subscription::query()->whereIn('subscriptions.id', $latestIds);

        $byPlan = (clone $current)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select('plans.name', 'subscriptions.status', DB::raw('count(*) as total'))
            ->groupBy('plans.name', 'subscriptions.status')
            ->get();

        $signups = Tenant::query()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn (Tenant $t) => $t->created_at->toDateString())
            ->map->count();

        return response()->json(['data' => [
            'tenants' => [
                'total' => (int) $tenantsByStatus->sum(),
                'by_status' => $tenantsByStatus,
            ],
            'users' => [
                'total' => User::query()->count(),
                'active_30d' => User::query()->where('last_login_at', '>=', now()->subDays(30))->count(),
            ],
            'subscriptions' => [
                'by_plan' => $byPlan,
                'trials_ending_7d' => (clone $current)
                    ->where('status', SubscriptionStatus::Trialing->value)
                    ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                    ->count(),
            ],
            'signups_30d' => $signups,
        ]]);
    }
}
