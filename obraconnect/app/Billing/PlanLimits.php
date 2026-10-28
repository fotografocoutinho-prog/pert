<?php

namespace App\Billing;

use App\Exceptions\ApiException;
use App\Models\Subscription;
use App\Models\Tenant;
use Closure;

/**
 * Limites e funcionalidades por subscrição. Os valores vêm de plans.limits,
 * sobrepostos por subscriptions.custom_limits. Nulo = ilimitado.
 */
class PlanLimits
{
    /** Rótulos para mensagens e interface. */
    public const LABELS = [
        'max_users' => 'Utilizadores',
        'max_active_projects' => 'Obras ativas',
        'storage_mb' => 'Armazenamento (MB)',
    ];

    /** @var array<string, Closure(Tenant): int> */
    private array $counters = [];

    public function __construct()
    {
        // Lugares ocupados = associações ativas + convites pendentes.
        $this->counters['max_users'] = fn (Tenant $tenant): int => $tenant->activeMemberships()->count()
            + $tenant->invitations()->pending()->count();

        $this->counters['storage_mb'] = fn (Tenant $tenant): int => (int) ceil($tenant->storage_used_bytes / 1048576);
    }

    /** Módulos futuros registam aqui o seu contador (ex.: max_active_projects na fase 2). */
    public function registerCounter(string $limit, Closure $counter): void
    {
        $this->counters[$limit] = $counter;
    }

    public function subscription(Tenant $tenant): ?Subscription
    {
        return $tenant->currentSubscription()->with('plan')->first();
    }

    /** @return array<string, int|null> */
    public function limits(Tenant $tenant): array
    {
        $subscription = $this->subscription($tenant);

        if ($subscription === null) {
            return [];
        }

        return array_replace($subscription->plan->limits ?? [], $subscription->custom_limits ?? []);
    }

    public function limit(Tenant $tenant, string $key): ?int
    {
        $limits = $this->limits($tenant);

        return isset($limits[$key]) ? (int) $limits[$key] : null;
    }

    public function usage(Tenant $tenant, string $key): ?int
    {
        return isset($this->counters[$key]) ? ($this->counters[$key])($tenant) : null;
    }

    /**
     * @return array<string, array{label: string, limit: int|null, used: int|null}>
     */
    public function summary(Tenant $tenant): array
    {
        $keys = array_unique(array_merge(array_keys($this->limits($tenant)), array_keys($this->counters)));

        return collect($keys)
            ->filter(fn (string $key) => isset($this->counters[$key]))
            ->mapWithKeys(fn (string $key) => [$key => [
                'label' => self::LABELS[$key] ?? $key,
                'limit' => $this->limit($tenant, $key),
                'used' => $this->usage($tenant, $key),
            ]])
            ->all();
    }

    public function assertCanAdd(Tenant $tenant, string $key, int $amount = 1): void
    {
        $limit = $this->limit($tenant, $key);

        if ($limit === null) {
            return;
        }

        $used = $this->usage($tenant, $key) ?? 0;

        if ($used + $amount > $limit) {
            $label = mb_strtolower(self::LABELS[$key] ?? $key);

            throw new ApiException(
                "Atingiu o limite do seu plano ({$label}: {$limit}). Atualize o plano para continuar.",
                'PLAN_LIMIT_REACHED',
                402,
                ['limit' => $key, 'max' => $limit, 'used' => $used],
            );
        }
    }

    public function hasFeature(Tenant $tenant, string $feature): bool
    {
        $subscription = $this->subscription($tenant);

        if ($subscription === null || ! $subscription->status->grantsAccess()) {
            return false;
        }

        return in_array($feature, $subscription->plan->features ?? [], true);
    }

    public function hasValidSubscription(Tenant $tenant): bool
    {
        return (bool) $this->subscription($tenant)?->status->grantsAccess();
    }
}
