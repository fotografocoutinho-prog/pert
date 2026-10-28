<?php

namespace App\Console\Commands;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Ciclo de vida (agendado diariamente):
 * trial terminado → expirada; período terminado → pagamento em atraso → expirada (após carência);
 * empresa sem subscrição válida → só leitura → suspensa (após N dias).
 * Nunca apaga dados.
 */
#[Signature('obraconnect:subscriptions-sweep')]
#[Description('Aplica o ciclo de vida das subscrições (trial, atraso, expiração, só leitura)')]
class SweepSubscriptions extends Command
{
    public function handle(PlanLimits $limits, Auditor $auditor): int
    {
        $now = now();
        $grace = (int) config('obraconnect.lifecycle.past_due_grace_days');
        $readOnlyDays = (int) config('obraconnect.lifecycle.read_only_days_before_suspension');

        $latestIds = Subscription::query()->selectRaw('max(id)')->groupBy('tenant_id');

        $expiredTrials = Subscription::query()->whereIn('id', $latestIds)
            ->where('status', SubscriptionStatus::Trialing->value)
            ->where('trial_ends_at', '<', $now)
            ->get();
        foreach ($expiredTrials as $subscription) {
            $subscription->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => $now])->save();
            $auditor->record('subscription.trial_expired', $subscription, [], tenantId: $subscription->tenant_id, actorType: 'system');
        }

        $pastDue = Subscription::query()->whereIn('id', $latestIds)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<', $now)
            ->get();
        foreach ($pastDue as $subscription) {
            $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
            $auditor->record('subscription.past_due', $subscription, [], tenantId: $subscription->tenant_id, actorType: 'system');
        }

        $overdue = Subscription::query()->whereIn('id', $latestIds)
            ->where('status', SubscriptionStatus::PastDue->value)
            ->where('current_period_ends_at', '<', $now->copy()->subDays($grace))
            ->get();
        foreach ($overdue as $subscription) {
            $subscription->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => $now])->save();
            $auditor->record('subscription.expired', $subscription, [], tenantId: $subscription->tenant_id, actorType: 'system');
        }

        $readOnly = 0;
        Tenant::query()->where('status', TenantStatus::Active->value)->lazyById()->each(function (Tenant $tenant) use ($limits, $auditor, &$readOnly) {
            if (! $limits->hasValidSubscription($tenant)) {
                $tenant->changeStatus(TenantStatus::ReadOnly, 'subscription_expired');
                $auditor->record('tenant.read_only', $tenant, [], tenantId: $tenant->id, actorType: 'system');
                $readOnly++;
            }
        });

        $suspended = 0;
        Tenant::query()
            ->where('status', TenantStatus::ReadOnly->value)
            ->where('status_reason', 'subscription_expired')
            ->where('status_changed_at', '<', $now->copy()->subDays($readOnlyDays))
            ->lazyById()->each(function (Tenant $tenant) use ($auditor, &$suspended) {
                $tenant->changeStatus(TenantStatus::Suspended, 'subscription_expired');
                $auditor->record('tenant.suspended', $tenant, ['reason' => 'subscription_expired'], tenantId: $tenant->id, actorType: 'system');
                $suspended++;
            });

        $this->info(sprintf(
            'Trials expirados: %d · Em atraso: %d · Expiradas: %d · Só leitura: %d · Suspensas: %d',
            $expiredTrials->count(), $pastDue->count(), $overdue->count(), $readOnly, $suspended,
        ));

        return self::SUCCESS;
    }
}
