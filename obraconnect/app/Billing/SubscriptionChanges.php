<?php

namespace App\Billing;

use App\Audit\Auditor;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mudanças de plano feitas pela própria empresa, sem pagamento online:
 *
 * - período experimental: muda logo de plano (o período experimental continua);
 * - subscrição paga, plano mais caro: muda logo e fica a faturar a diferença até ao fim do período;
 * - plano mais barato ou troca de ciclo: fica marcado para o fim do período;
 * - ativação/renovação: pedido a pagar; a equipa de faturação confirma depois de faturar e só aí o
 *   período conta. Descer de plano só se o que a empresa já usa couber no plano novo.
 *
 * Subscrições com condições próprias (limites à medida, plano não público, sem fim de período) ficam
 * com a equipa da plataforma.
 */
final class SubscriptionChanges
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly Coupons $coupons,
        private readonly Auditor $auditor,
        private readonly BillingNotifier $notifier,
    ) {}

    public static function price(Plan $plan, string $cycle): ?int
    {
        return $cycle === 'yearly' ? $plan->price_yearly_cents : $plan->price_monthly_cents;
    }

    public static function addCycle(CarbonInterface $from, string $cycle): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);

        return $cycle === 'yearly' ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
    }

    public function current(Tenant $tenant): ?Subscription
    {
        return $tenant->currentSubscription()->with(['plan', 'scheduledPlan'])->first();
    }

    /**
     * Limites do plano que a empresa já passa (o que teria de reduzir antes de descer).
     *
     * @return list<array{key: string, label: string, used: int, limit: int}>
     */
    public function overLimits(Tenant $tenant, Plan $plan): array
    {
        $over = [];
        foreach ($plan->limits ?? [] as $key => $limit) {
            if ($limit === null) {
                continue;
            }
            $used = $this->limits->usage($tenant, $key);
            if ($used !== null && $used > (int) $limit) {
                $over[] = ['key' => $key, 'label' => PlanLimits::LABELS[$key] ?? $key, 'used' => $used, 'limit' => (int) $limit];
            }
        }

        return $over;
    }

    /** Desde quando se pode renovar (null = não há período a renovar). */
    public function renewalOpensAt(?Subscription $s): ?CarbonImmutable
    {
        if ($s === null || $s->status !== SubscriptionStatus::Active || $s->current_period_ends_at === null) {
            return null;
        }

        return CarbonImmutable::instance($s->current_period_ends_at)->subDays((int) config('obraconnect.billing.renewal_window_days', 30));
    }

    public function pendingOrder(Tenant $tenant): ?SubscriptionOrder
    {
        return $tenant->subscriptionOrders()->where('status', 'pending')->whereIn('kind', ['activation', 'renewal'])->latest('id')->first();
    }

    // --- mudar de plano -----------------------------------------------------------------------------

    /**
     * O que acontece ao mudar para este plano e ciclo (sem gravar).
     *
     * @return array{action: string, amount_cents: int, effective_at: CarbonImmutable, period_ends_at: ?CarbonImmutable, cycle_change_at: ?CarbonImmutable, over: list<array<string, mixed>>, message: string}
     */
    public function planChange(Tenant $tenant, Plan $plan, string $cycle, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $s = $this->current($tenant);
        $this->assertSelfService($s);
        $this->assertAvailable($plan, $cycle);
        $running = $s !== null && ($s->status === SubscriptionStatus::Trialing
            || ($s->status === SubscriptionStatus::Active && $s->current_period_ends_at !== null && $s->current_period_ends_at->gt($now)));
        if (! $running) {
            throw ApiException::conflict('Não há subscrição em curso: peça a ativação ou a renovação com o plano que quiser.', 'SUBSCRIPTION_RENEW_FIRST');
        }
        if ($s->plan_id === $plan->id && $s->billing_cycle === $cycle) {
            throw ApiException::conflict("Já está no plano {$plan->name} com faturação ".BillingNotifier::cycle($cycle).'.', 'SUBSCRIPTION_NO_CHANGE');
        }
        $over = $this->overLimits($tenant, $plan);
        $cycleLabel = BillingNotifier::cycle($cycle);

        if ($s->status === SubscriptionStatus::Trialing) {
            $until = $s->trial_ends_at?->timezone('Europe/Lisbon')->format('d/m/Y');

            return ['action' => 'switch', 'amount_cents' => 0, 'effective_at' => $now, 'period_ends_at' => null, 'cycle_change_at' => null, 'over' => $over,
                'message' => "Muda já para o plano {$plan->name} ({$cycleLabel}). O período experimental continua".($until ? " até {$until}" : '').', sem custos.'];
        }

        $end = CarbonImmutable::instance($s->current_period_ends_at);
        $endLabel = $end->timezone('Europe/Lisbon')->format('d/m/Y');
        $isUpgrade = $s->plan_id !== $plan->id && (int) $plan->price_monthly_cents > (int) $s->plan->price_monthly_cents;
        if ($isUpgrade) {
            $newPrice = self::price($plan, $s->billing_cycle)
                ?? throw ApiException::unprocessable("O plano {$plan->name} não tem preço ".BillingNotifier::cycle($s->billing_cycle).'.', 'PLAN_CONTACT_SALES');
            $oldPrice = self::price($s->plan, $s->billing_cycle) ?? 0;
            $start = CarbonImmutable::instance($s->current_period_starts_at ?? $now);
            $total = max(1, $end->getTimestamp() - $start->getTimestamp());
            $left = max(0, $end->getTimestamp() - $now->getTimestamp());
            $amount = max(0, (int) round(($newPrice - $oldPrice) * $left / $total));
            $cycleChange = $cycle !== $s->billing_cycle ? $end : null;

            return ['action' => 'upgrade', 'amount_cents' => $amount, 'effective_at' => $now, 'period_ends_at' => $end, 'cycle_change_at' => $cycleChange, 'over' => $over,
                'message' => "Muda já para o plano {$plan->name}. ".($amount > 0 ? 'Fica a faturar '.BillingNotifier::money($amount)." sem IVA (a diferença até {$endLabel})." : 'Sem diferença a pagar.')
                    .($cycleChange ? " A faturação passa a {$cycleLabel} na renovação, a {$endLabel}." : '')];
        }

        return ['action' => 'schedule', 'amount_cents' => 0, 'effective_at' => $end, 'period_ends_at' => $end, 'cycle_change_at' => null, 'over' => $over,
            'message' => "Continua no plano {$s->plan->name} até {$endLabel} (já pago) e muda para o {$plan->name} ({$cycleLabel}) nesse dia."];
    }

    /** @return array{action: string, subscription: Subscription, order: ?SubscriptionOrder} */
    public function changePlan(Tenant $tenant, Plan $plan, string $cycle, User $user): array
    {
        $quote = $this->planChange($tenant, $plan, $cycle);
        $this->assertFits($plan, $quote['over']);

        [$subscription, $order] = DB::transaction(function () use ($tenant, $plan, $cycle, $user, $quote): array {
            $s = $tenant->subscriptions()->latest('id')->lockForUpdate()->firstOrFail();
            $from = $s->plan_id;
            $order = null;
            switch ($quote['action']) {
                case 'switch':
                    $s->forceFill(['plan_id' => $plan->id, 'billing_cycle' => $cycle] + self::noSchedule())->save();
                    $this->auditor->record('subscription.plan_switched', $s, ['from_plan_id' => $from, 'plan' => $plan->code, 'billing_cycle' => $cycle]);
                    break;
                case 'upgrade':
                    $s->forceFill(['plan_id' => $plan->id] + ($quote['cycle_change_at']
                        ? ['scheduled_plan_id' => $plan->id, 'scheduled_billing_cycle' => $cycle, 'scheduled_change_at' => $quote['cycle_change_at']]
                        : self::noSchedule()))->save();
                    if ($quote['amount_cents'] > 0) {
                        $order = $tenant->subscriptionOrders()->create([
                            'subscription_id' => $s->id, 'kind' => 'upgrade', 'plan_id' => $plan->id, 'from_plan_id' => $from,
                            'billing_cycle' => $s->billing_cycle, 'list_price_cents' => $quote['amount_cents'], 'discount_cents' => 0,
                            'total_cents' => $quote['amount_cents'], 'currency' => $plan->currency ?: 'EUR', 'status' => 'pending',
                            'period_starts_at' => now(), 'period_ends_at' => $quote['period_ends_at'], 'requested_by_user_id' => $user->id,
                        ]);
                    }
                    $this->auditor->record('subscription.upgraded', $s, ['from_plan_id' => $from, 'plan' => $plan->code, 'amount_cents' => $quote['amount_cents'], 'order_id' => $order?->id]);
                    break;
                default:
                    $s->forceFill(['scheduled_plan_id' => $plan->id, 'scheduled_billing_cycle' => $cycle, 'scheduled_change_at' => $quote['effective_at']])->save();
                    $this->auditor->record('subscription.change_scheduled', $s, ['plan' => $plan->code, 'billing_cycle' => $cycle, 'at' => $quote['effective_at']->toIso8601String()]);
            }

            return [$s, $order];
        });

        if ($order) {
            $this->notifier->upgradeApplied($order);
        }

        return ['action' => $quote['action'], 'subscription' => $subscription->refresh()->load(['plan', 'scheduledPlan']), 'order' => $order];
    }

    public function cancelScheduled(Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($tenant): Subscription {
            $s = $tenant->subscriptions()->latest('id')->lockForUpdate()->first();
            if ($s === null || $s->scheduled_change_at === null) {
                throw ApiException::conflict('Não há nenhuma mudança marcada.', 'SUBSCRIPTION_NO_SCHEDULED');
            }
            $this->auditor->record('subscription.change_canceled', $s, ['plan_id' => $s->scheduled_plan_id, 'billing_cycle' => $s->scheduled_billing_cycle]);
            $s->forceFill(self::noSchedule())->save();

            return $s->load(['plan', 'scheduledPlan']);
        });
    }

    // --- pedidos de ativação e renovação ------------------------------------------------------------

    /**
     * @return array{kind: string, list_price_cents: int, discount_cents: int, total_cents: int, coupon: ?Coupon, period_starts_at: CarbonImmutable, period_ends_at: CarbonImmutable, over: list<array<string, mixed>>, message: string}
     */
    public function orderQuote(Tenant $tenant, Plan $plan, string $cycle, ?string $couponCode, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $s = $this->current($tenant);
        $this->assertSelfService($s);
        $price = $this->assertAvailable($plan, $cycle);
        if ($s !== null && $s->status === SubscriptionStatus::Active) {
            $opens = $this->renewalOpensAt($s)
                ?? throw ApiException::conflict('A subscrição não tem fim de período: fale com a equipa ObraConnect.', 'SUBSCRIPTION_CUSTOM');
            if ($opens->gt($now)) {
                throw ApiException::conflict('Pode renovar a partir de '.$opens->timezone('Europe/Lisbon')->format('d/m/Y').' ('.config('obraconnect.billing.renewal_window_days').' dias antes do fim do período).', 'SUBSCRIPTION_NOT_DUE');
            }
        }
        if ($this->pendingOrder($tenant)) {
            throw ApiException::conflict('Já há um pedido por confirmar. Cancele-o primeiro se quiser mudar o plano ou o ciclo.', 'ORDER_PENDING');
        }
        $coupon = filled($couponCode) ? $this->coupons->forOrder((string) $couponCode, $tenant, $plan, $cycle, $now) : null;
        $discount = $coupon?->discountFor($price) ?? 0;
        [$start, $end] = $this->periodFor($s, $cycle, $now);
        $kind = $s !== null && $s->current_period_ends_at !== null ? 'renewal' : 'activation';
        $fmt = fn (CarbonInterface $d) => $d->timezone('Europe/Lisbon')->format('d/m/Y');

        return [
            'kind' => $kind, 'list_price_cents' => $price, 'discount_cents' => $discount, 'total_cents' => $price - $discount, 'coupon' => $coupon,
            'period_starts_at' => $start, 'period_ends_at' => $end, 'over' => $this->overLimits($tenant, $plan),
            'message' => ($kind === 'renewal' ? 'Renovação' : 'Ativação')." do plano {$plan->name} (".BillingNotifier::cycle($cycle).'): '
                .BillingNotifier::money($price - $discount).' sem IVA'.($coupon ? " com o cupão {$coupon->code}" : '')
                .". Período previsto de {$fmt($start)} a {$fmt($end)}; conta a partir da confirmação do pagamento.",
        ];
    }

    public function createOrder(Tenant $tenant, Plan $plan, string $cycle, ?string $couponCode, User $user): SubscriptionOrder
    {
        $quote = $this->orderQuote($tenant, $plan, $cycle, $couponCode);
        $this->assertFits($plan, $quote['over']);

        $order = DB::transaction(function () use ($tenant, $plan, $cycle, $user, $quote): SubscriptionOrder {
            // Um pedido de cada vez: bloqueia a empresa e volta a confirmar.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            if ($this->pendingOrder($tenant)) {
                throw ApiException::conflict('Já há um pedido por confirmar.', 'ORDER_PENDING');
            }
            $order = $tenant->subscriptionOrders()->create([
                'subscription_id' => $tenant->currentSubscription()->value('id'),
                'kind' => $quote['kind'], 'plan_id' => $plan->id, 'from_plan_id' => $tenant->currentSubscription()->value('plan_id'),
                'billing_cycle' => $cycle, 'list_price_cents' => $quote['list_price_cents'], 'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'], 'currency' => $plan->currency ?: 'EUR', 'coupon_id' => $quote['coupon']?->id,
                'status' => 'pending', 'period_starts_at' => $quote['period_starts_at'], 'period_ends_at' => $quote['period_ends_at'],
                'requested_by_user_id' => $user->id,
            ]);
            if ($quote['coupon']) {
                $this->coupons->redeem($quote['coupon'], $tenant, $order, $user, $quote['discount_cents']);
            }
            $this->auditor->record('subscription.order_created', $order, ['kind' => $order->kind, 'plan' => $plan->code, 'billing_cycle' => $cycle,
                'total_cents' => $order->total_cents, 'coupon' => $quote['coupon']?->code]);

            return $order;
        });
        $this->notifier->orderCreated($order);

        return $order;
    }

    public function cancelOrder(SubscriptionOrder $order, ?string $reason, bool $byPlatform): SubscriptionOrder
    {
        DB::transaction(function () use ($order, $reason, $byPlatform): void {
            $locked = SubscriptionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ApiException::conflict('Este pedido já não está por confirmar.', 'ORDER_NOT_PENDING');
            }
            if (! $byPlatform && ! $locked->needsPayment()) {
                throw ApiException::conflict('A subida de plano já está aplicada: fale com a equipa ObraConnect.', 'ORDER_NOT_CANCELABLE');
            }
            $locked->forceFill(['status' => 'canceled', 'canceled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->coupons->release($locked);
            $this->auditor->record($byPlatform ? 'platform.subscription_order_canceled' : 'subscription.order_canceled', $locked,
                ['reason' => $reason], tenantId: $locked->tenant_id);
        });
        $order->refresh();
        if ($byPlatform) {
            $this->notifier->orderCanceled($order, $reason);
        }

        return $order;
    }

    /** A equipa de faturação confirma o pagamento: ativa ou prolonga o período (as subidas ficam só faturadas). */
    public function confirmOrder(SubscriptionOrder $order, User $staff, ?string $invoiceReference, ?string $notes): SubscriptionOrder
    {
        DB::transaction(function () use ($order, $staff, $invoiceReference, $notes): void {
            $locked = SubscriptionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ApiException::conflict('Este pedido já não está por confirmar.', 'ORDER_NOT_PENDING');
            }
            $fields = ['status' => 'confirmed', 'confirmed_by_user_id' => $staff->id, 'confirmed_at' => now(),
                'invoice_reference' => $invoiceReference, 'notes' => $notes];

            if ($locked->needsPayment()) {
                $tenant = Tenant::query()->findOrFail($locked->tenant_id);
                [$s, $start, $end] = $this->applyPaidPeriod($tenant, $locked);
                $fields += ['subscription_id' => $s->id, 'period_starts_at' => $start, 'period_ends_at' => $end];
                if ($tenant->status !== TenantStatus::Active && $tenant->status_reason === 'subscription_expired') {
                    $tenant->changeStatus(TenantStatus::Active);
                }
            }
            $locked->forceFill($fields)->save();
            $this->auditor->record('platform.subscription_order_confirmed', $locked, ['invoice_reference' => $invoiceReference,
                'period_ends_at' => $locked->period_ends_at?->toIso8601String()], tenantId: $locked->tenant_id);
        });
        $order->refresh();
        $this->notifier->orderConfirmed($order);

        return $order;
    }

    // --- período experimental -----------------------------------------------------------------------

    public function extendTrial(Tenant $tenant, string $code, User $user): Subscription
    {
        $now = CarbonImmutable::now();
        $s = $this->current($tenant);
        $neverPaid = $s !== null && $s->current_period_ends_at === null;
        if ($s === null || ! ($s->status === SubscriptionStatus::Trialing || ($s->status === SubscriptionStatus::Expired && $neverPaid))) {
            throw ApiException::unprocessable('Este cupão só serve durante o período experimental (ou logo a seguir, antes da primeira ativação).', 'COUPON_NOT_APPLICABLE');
        }
        $coupon = $this->coupons->forTrial($code, $tenant, $now);

        $s = DB::transaction(function () use ($tenant, $coupon, $user, $now): Subscription {
            $s = $tenant->subscriptions()->latest('id')->lockForUpdate()->firstOrFail();
            $this->coupons->redeem($coupon, $tenant, null, $user, 0, $coupon->trial_extension_days);
            $base = $s->trial_ends_at !== null && $s->trial_ends_at->gt($now) ? CarbonImmutable::instance($s->trial_ends_at) : $now;
            $s->forceFill(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $base->addDays($coupon->trial_extension_days), 'ends_at' => null])->save();
            $this->auditor->record('subscription.trial_extended', $s, ['coupon' => $coupon->code, 'days' => $coupon->trial_extension_days,
                'trial_ends_at' => $s->trial_ends_at->toIso8601String()]);
            $fresh = Tenant::query()->findOrFail($tenant->id);
            if ($fresh->status !== TenantStatus::Active && $fresh->status_reason === 'subscription_expired') {
                $fresh->changeStatus(TenantStatus::Active);
            }

            return $s;
        });

        return $s->load(['plan', 'scheduledPlan']);
    }

    // --- tarefas diárias ----------------------------------------------------------------------------

    /** Mudanças marcadas cujo dia chegou (corre antes de passar as subscrições a "em atraso"). */
    public function applyScheduled(CarbonInterface $now): int
    {
        $latest = Subscription::query()->selectRaw('max(id)')->groupBy('tenant_id');
        $due = Subscription::query()->whereIn('id', $latest)->whereNotNull('scheduled_change_at')->where('scheduled_change_at', '<=', $now)->get();
        foreach ($due as $s) {
            $properties = ['from_plan_id' => $s->plan_id, 'plan_id' => $s->scheduled_plan_id, 'billing_cycle' => $s->scheduled_billing_cycle];
            $s->forceFill(array_filter(['plan_id' => $s->scheduled_plan_id, 'billing_cycle' => $s->scheduled_billing_cycle]) + self::noSchedule())->save();
            $this->auditor->record('subscription.scheduled_applied', $s, $properties, tenantId: $s->tenant_id, actorType: 'system');
        }

        return $due->count();
    }

    /** Pedidos por pagar há demasiado tempo: caducam e libertam o cupão. */
    public function expireStaleOrders(CarbonInterface $now): int
    {
        $limit = CarbonImmutable::instance($now)->subDays((int) config('obraconnect.billing.pending_order_days', 30));
        $stale = SubscriptionOrder::query()->where('status', 'pending')->whereIn('kind', ['activation', 'renewal'])->where('created_at', '<', $limit)->get();
        foreach ($stale as $order) {
            $order->forceFill(['status' => 'expired', 'canceled_at' => $now])->save();
            $this->coupons->release($order);
            $this->auditor->record('subscription.order_expired', $order, [], tenantId: $order->tenant_id, actorType: 'system');
        }

        return $stale->count();
    }

    // --- interno ------------------------------------------------------------------------------------

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} início e fim do período pago (previsto) */
    private function periodFor(?Subscription $s, string $cycle, CarbonImmutable $now): array
    {
        if ($s !== null && $s->status === SubscriptionStatus::Active && $s->current_period_ends_at?->gt($now)) {
            $start = CarbonImmutable::instance($s->current_period_ends_at);
        } elseif ($s !== null && $s->status === SubscriptionStatus::PastDue && $s->current_period_ends_at !== null
            && self::addCycle($s->current_period_ends_at, $cycle)->gt($now)) {
            // Em atraso: a empresa continuou a usar durante a carência, por isso o período novo encosta ao antigo.
            $start = CarbonImmutable::instance($s->current_period_ends_at);
        } elseif ($s !== null && $s->status === SubscriptionStatus::Trialing && $s->trial_ends_at?->gt($now)) {
            $start = CarbonImmutable::instance($s->trial_ends_at);
        } else {
            $start = $now;
        }

        return [$start, self::addCycle($start, $cycle)];
    }

    /** @return array{0: Subscription, 1: CarbonImmutable, 2: CarbonImmutable} */
    private function applyPaidPeriod(Tenant $tenant, SubscriptionOrder $order): array
    {
        $now = CarbonImmutable::now();
        $s = $tenant->subscriptions()->latest('id')->lockForUpdate()->first();
        if ($s === null) {
            $s = new Subscription;
            $s->tenant()->associate($tenant);
        }
        [$start, $end] = $this->periodFor($s->exists ? $s : null, $order->billing_cycle, $now);

        if ($s->exists && $s->status === SubscriptionStatus::Active && $s->current_period_ends_at?->gt($now)) {
            // Renovação antecipada: o período atual segue até ao fim e o novo encosta-lhe. Se o plano ou o
            // ciclo mudam, mudam nesse dia.
            $change = $order->plan_id !== $s->plan_id || $order->billing_cycle !== $s->billing_cycle;
            $s->forceFill(['current_period_ends_at' => $end] + ($change
                ? ['scheduled_plan_id' => $order->plan_id, 'scheduled_billing_cycle' => $order->billing_cycle, 'scheduled_change_at' => $start]
                : self::noSchedule()))->save();
        } else {
            $s->forceFill([
                'plan_id' => $order->plan_id, 'billing_cycle' => $order->billing_cycle, 'status' => SubscriptionStatus::Active,
                'current_period_starts_at' => $start, 'current_period_ends_at' => $end, 'canceled_at' => null, 'ends_at' => null,
            ] + self::noSchedule())->save();
        }

        return [$s, $start, $end];
    }

    /** @return array{scheduled_plan_id: null, scheduled_billing_cycle: null, scheduled_change_at: null} */
    private static function noSchedule(): array
    {
        return ['scheduled_plan_id' => null, 'scheduled_billing_cycle' => null, 'scheduled_change_at' => null];
    }

    /** Condições combinadas com a plataforma: limites à medida, plano não público ou ativa sem fim de período. */
    public static function hasCustomTerms(?Subscription $s): bool
    {
        return $s !== null && (! empty($s->custom_limits) || ($s->plan !== null && ! $s->plan->is_public)
            || ($s->status === SubscriptionStatus::Active && $s->current_period_ends_at === null));
    }

    private function assertSelfService(?Subscription $s): void
    {
        if (self::hasCustomTerms($s)) {
            throw ApiException::conflict('A sua subscrição tem condições próprias combinadas com a equipa ObraConnect: fale connosco para a mudar.', 'SUBSCRIPTION_CUSTOM');
        }
    }

    private function assertAvailable(Plan $plan, string $cycle): int
    {
        if (! $plan->is_active || ! $plan->is_public) {
            throw ApiException::unprocessable('Este plano não está disponível.', 'PLAN_UNAVAILABLE');
        }

        return self::price($plan, $cycle)
            ?? throw ApiException::unprocessable("O plano {$plan->name} tem preço sob consulta: fale com a equipa ObraConnect.", 'PLAN_CONTACT_SALES');
    }

    /** @param  list<array{label: string, used: int, limit: int}>  $over */
    private function assertFits(Plan $plan, array $over): void
    {
        if ($over !== []) {
            throw new ApiException("O plano {$plan->name} não chega para o que a empresa já usa: "
                .implode('; ', array_map(fn ($o) => mb_strtolower($o['label']).": {$o['used']} (máximo {$o['limit']})", $over)).'.', 'SUBSCRIPTION_OVER_LIMITS', 422, ['over' => $over]);
        }
    }
}
