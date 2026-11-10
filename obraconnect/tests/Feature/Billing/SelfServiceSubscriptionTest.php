<?php

namespace Tests\Feature\Billing;

use App\Enums\PlatformRole;
use App\Enums\TenantStatus;
use App\Mail\SubscriptionNoticeMail;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SelfServiceSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-11-10 10:00:00');
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function sub(Tenant $tenant): Subscription
    {
        return Subscription::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
    }

    private function coupon(array $attributes): Coupon
    {
        return Coupon::query()->create($attributes + ['is_active' => true]);
    }

    private function body(string $plan, string $cycle = 'monthly', array $extra = []): array
    {
        return ['plan_id' => $this->plan($plan)->id, 'billing_cycle' => $cycle] + $extra;
    }

    private function active(Tenant $tenant, string $plan, string $cycle, string $start, string $end): void
    {
        $this->sub($tenant)->forceFill(['status' => 'active', 'plan_id' => $this->plan($plan)->id, 'billing_cycle' => $cycle, 'trial_ends_at' => null,
            'current_period_starts_at' => $start, 'current_period_ends_at' => $end])->save();
    }

    public function test_trial_switch_is_immediate_but_never_below_what_the_company_uses(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $trialEnds = $this->sub($tenant)->trial_ends_at->toIso8601String();
        $this->actingAs($owner);
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/projects', ['name' => "Obra {$i}", 'starts_on' => '2026-11-02'])->assertCreated();
        }

        $this->postJson('/api/v1/subscription/quote', $this->body('start') + ['intent' => 'change'])->assertOk()
            ->assertJsonPath('data.action', 'switch')->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.over.0.key', 'max_active_projects')->assertJsonPath('data.over.0.used', 4)->assertJsonPath('data.over.0.limit', 3);
        $this->postJson('/api/v1/subscription/change', $this->body('start'))->assertUnprocessable()->assertJsonPath('code', 'SUBSCRIPTION_OVER_LIMITS');

        $this->postJson('/api/v1/subscription/change', $this->body('business', 'yearly'))->assertOk()
            ->assertJsonPath('data.action', 'switch')->assertJsonPath('data.subscription.plan.code', 'business')
            ->assertJsonPath('data.subscription.billing_cycle', 'yearly')->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.order', null);
        $this->assertSame($trialEnds, $this->sub($tenant)->trial_ends_at->toIso8601String());
        $this->postJson('/api/v1/subscription/change', $this->body('business', 'yearly'))->assertConflict()->assertJsonPath('code', 'SUBSCRIPTION_NO_CHANGE');
        $this->postJson('/api/v1/subscription/change', $this->body('enterprise'))->assertUnprocessable()->assertJsonPath('code', 'PLAN_CONTACT_SALES');
        $this->assertSame(1, AuditLog::query()->where('event', 'subscription.plan_switched')->count());
        Mail::assertNothingQueued();
    }

    public function test_activation_with_a_coupon_is_confirmed_by_billing_and_starts_when_the_trial_ends(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        [$owner, $tenant] = $this->createCompany();
        $trialEnds = $this->sub($tenant)->trial_ends_at;
        $this->coupon(['code' => 'LANCAMENTO', 'percent_off' => 20, 'billing_cycles' => ['yearly'], 'max_redemptions' => 2]);
        $this->actingAs($owner);

        $this->postJson('/api/v1/subscription/quote', $this->body('professional', 'monthly', ['intent' => 'order', 'coupon_code' => 'lancamento']))
            ->assertUnprocessable()->assertJsonPath('code', 'COUPON_NOT_FOR_CYCLE');
        $this->postJson('/api/v1/subscription/quote', $this->body('professional', 'yearly', ['intent' => 'order', 'coupon_code' => 'lancamento']))->assertOk()
            ->assertJsonPath('data.action', 'activation')->assertJsonPath('data.list_price_cents', 129000)
            ->assertJsonPath('data.discount_cents', 25800)->assertJsonPath('data.total_cents', 103200)
            ->assertJsonPath('data.period_starts_at', $trialEnds->toIso8601String());

        // Quem não tem subscription.manage não muda nada.
        $director = $this->addMember($tenant, 'diretor_tecnico');
        $this->actingAs($director)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson('/api/v1/subscription/orders', $this->body('professional', 'yearly'))->assertForbidden();

        $order = $this->actingAs($owner)->postJson('/api/v1/subscription/orders', $this->body('professional', 'yearly', ['coupon_code' => 'LANCAMENTO']))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.kind', 'activation')
            ->assertJsonPath('data.total_cents', 103200)->assertJsonPath('data.coupon', 'LANCAMENTO')->json('data.id');
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($billing->email) && str_contains($m->subjectLine, "n.º {$order}"));
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($owner->email) && str_contains(implode(' ', $m->lines), 'indicações de pagamento'));
        $this->postJson('/api/v1/subscription/orders', $this->body('professional', 'monthly'))->assertConflict()->assertJsonPath('code', 'ORDER_PENDING');
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.can_manage', true)->assertJsonPath('data.orders.0.id', $order);
        $this->assertSame('trialing', $this->sub($tenant)->status->value);

        // Só a faturação (ou super administrador) confirma.
        $this->actingAs($this->platformUser(PlatformRole::Support))->getJson('/api/v1/platform/subscription-orders')->assertForbidden();
        $this->actingAs($billing)->getJson('/api/v1/platform/subscription-orders')->assertOk()
            ->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.tenant.id', $tenant->id);
        $this->postJson("/api/v1/platform/subscription-orders/{$order}/confirm", ['invoice_reference' => 'FT 2026/15'])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.invoice_reference', 'FT 2026/15');
        $this->postJson("/api/v1/platform/subscription-orders/{$order}/confirm")->assertConflict()->assertJsonPath('code', 'ORDER_NOT_PENDING');

        $s = $this->sub($tenant);
        $this->assertSame(['active', 'professional', 'yearly'], [$s->status->value, $s->plan->code, $s->billing_cycle]);
        $this->assertSame($trialEnds->toIso8601String(), $s->current_period_starts_at->toIso8601String());
        $this->assertSame($trialEnds->copy()->addYear()->toIso8601String(), $s->current_period_ends_at->toIso8601String());
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($owner->email) && str_contains($m->subjectLine, 'confirmada'));

        // O cupão: uma vez por empresa, até ao limite de usos.
        $this->actingAs($owner)->postJson('/api/v1/subscription/quote', $this->body('professional', 'yearly', ['intent' => 'order', 'coupon_code' => 'LANCAMENTO']))
            ->assertStatus(409); // já não está na altura de renovar (a regra da renovação vem antes do cupão)
        [$ownerB] = $this->createCompany('Empresa B');
        $this->actingAs($ownerB)->postJson('/api/v1/subscription/orders', $this->body('start', 'yearly', ['coupon_code' => 'LANCAMENTO']))->assertCreated();
        [$ownerC] = $this->createCompany('Empresa C');
        $this->actingAs($ownerC)->postJson('/api/v1/subscription/quote', $this->body('start', 'yearly', ['intent' => 'order', 'coupon_code' => 'LANCAMENTO']))
            ->assertUnprocessable()->assertJsonPath('code', 'COUPON_EXHAUSTED');
    }

    public function test_an_expired_company_in_read_only_asks_for_activation_and_comes_back(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        [$owner, $tenant] = $this->createCompany();
        $this->coupon(['code' => 'VOLTA', 'amount_off_cents' => 5000]);
        $this->sub($tenant)->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();
        $this->assertSame(TenantStatus::ReadOnly, $tenant->refresh()->status);

        $this->actingAs($owner)->putJson('/api/v1/company', ['name' => 'Novo', 'country_code' => 'PT'])->assertStatus(423);
        $this->postJson('/api/v1/subscription/change', $this->body('start'))->assertStatus(423);
        $first = $this->postJson('/api/v1/subscription/orders', $this->body('professional', 'monthly', ['coupon_code' => 'VOLTA']))->assertCreated()
            ->assertJsonPath('data.total_cents', 7900)->json('data.id');
        $this->assertSame(1, CouponRedemption::query()->count());
        // Cancelar liberta o cupão.
        $this->postJson("/api/v1/subscription/orders/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame(0, CouponRedemption::query()->count());
        $order = $this->postJson('/api/v1/subscription/orders', $this->body('professional', 'monthly', ['coupon_code' => 'VOLTA']))->assertCreated()->json('data.id');

        // Outra empresa não vê nem cancela este pedido.
        [$other] = $this->createCompany('Empresa B');
        $this->actingAs($other)->postJson("/api/v1/subscription/orders/{$order}/cancel")->assertNotFound();

        $this->actingAs($billing)->postJson("/api/v1/platform/subscription-orders/{$order}/confirm")->assertOk();
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $s = $this->sub($tenant);
        $this->assertSame('active', $s->status->value);
        $this->assertSame('2026-11-10 10:00:00', $s->current_period_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-10 10:00:00', $s->current_period_ends_at->format('Y-m-d H:i:s'));
        $this->actingAs($owner)->putJson('/api/v1/company', ['name' => 'Novo', 'country_code' => 'PT'])->assertOk();
    }

    public function test_upgrading_mid_period_is_immediate_and_leaves_the_difference_to_invoice(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        [$owner, $tenant] = $this->createCompany();
        $this->active($tenant, 'professional', 'monthly', '2026-11-01 00:00:00', '2026-12-01 00:00:00');
        $this->travelTo('2026-11-16 00:00:00');
        $this->actingAs($owner);

        $this->postJson('/api/v1/subscription/quote', $this->body('business') + ['intent' => 'change'])->assertOk()
            ->assertJsonPath('data.action', 'upgrade')->assertJsonPath('data.total_cents', 8500);
        $order = $this->postJson('/api/v1/subscription/change', $this->body('business'))->assertOk()
            ->assertJsonPath('data.action', 'upgrade')->assertJsonPath('data.subscription.plan.code', 'business')
            ->assertJsonPath('data.order.kind', 'upgrade')->assertJsonPath('data.order.total_cents', 8500)->json('data.order.id');
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($billing->email) && str_contains($m->subjectLine, 'Subida de plano'));
        $this->assertSame('2026-12-01', $this->sub($tenant)->current_period_ends_at->toDateString());

        $this->postJson("/api/v1/subscription/orders/{$order}/cancel")->assertConflict()->assertJsonPath('code', 'ORDER_NOT_CANCELABLE');
        $this->actingAs($billing)->postJson("/api/v1/platform/subscription-orders/{$order}/confirm", ['invoice_reference' => 'FT 2026/20'])->assertOk();
        $s = $this->sub($tenant);
        $this->assertSame(['business', 'active', '2026-12-01'], [$s->plan->code, $s->status->value, $s->current_period_ends_at->toDateString()]);
    }

    public function test_downgrades_wait_for_the_end_of_the_period_and_early_renewal_extends_it(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        [$owner, $tenant] = $this->createCompany();
        $this->active($tenant, 'business', 'yearly', '2026-01-15 00:00:00', '2027-01-15 00:00:00');
        $this->actingAs($owner);

        $this->postJson('/api/v1/subscription/orders', $this->body('business', 'yearly'))->assertConflict()->assertJsonPath('code', 'SUBSCRIPTION_NOT_DUE');
        $this->postJson('/api/v1/subscription/change', $this->body('start'))->assertOk()->assertJsonPath('data.action', 'schedule')
            ->assertJsonPath('data.subscription.plan.code', 'business')->assertJsonPath('data.subscription.scheduled_change.plan.code', 'start')
            ->assertJsonPath('data.subscription.scheduled_change.billing_cycle', 'monthly');
        $this->deleteJson('/api/v1/subscription/scheduled-change')->assertOk()->assertJsonPath('data.scheduled_change', null);
        $this->deleteJson('/api/v1/subscription/scheduled-change')->assertConflict();
        $this->postJson('/api/v1/subscription/change', $this->body('start'))->assertOk();

        // Dentro da janela de renovação (30 dias antes), renova já com o plano novo.
        $this->travelTo('2026-12-20 10:00:00');
        $order = $this->postJson('/api/v1/subscription/orders', $this->body('start'))->assertCreated()
            ->assertJsonPath('data.kind', 'renewal')->assertJsonPath('data.total_cents', 4900)->json('data');
        $this->assertSame('2027-01-15', substr($order['period_starts_at'], 0, 10));
        $this->actingAs($billing)->postJson("/api/v1/platform/subscription-orders/{$order['id']}/confirm")->assertOk();
        $s = $this->sub($tenant);
        $this->assertSame(['business', '2027-02-15', 'start', '2027-01-15'], [$s->plan->code, $s->current_period_ends_at->toDateString(),
            $s->scheduledPlan->code, $s->scheduled_change_at->toDateString()]);

        // No dia, a tarefa diária muda o plano; o período pago continua.
        $this->travelTo('2027-01-15 02:15:00');
        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();
        $s = $this->sub($tenant);
        $this->assertSame(['start', 'monthly', 'active', null], [$s->plan->code, $s->billing_cycle, $s->status->value, $s->scheduled_change_at]);
        $this->assertSame(1, AuditLog::query()->where('event', 'subscription.scheduled_applied')->count());
    }

    public function test_trial_extension_coupons(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $trialEnds = $this->sub($tenant)->trial_ends_at;
        $this->coupon(['code' => 'MAISTEMPO', 'trial_extension_days' => 15]);
        $this->coupon(['code' => 'DESCONTO', 'percent_off' => 10]);
        $this->coupon(['code' => 'VELHO', 'trial_extension_days' => 5, 'valid_until' => now()->subDay()]);
        $this->coupon(['code' => 'FUTURO', 'trial_extension_days' => 5, 'valid_from' => now()->addDay()]);
        $this->coupon(['code' => 'DESLIGADO', 'trial_extension_days' => 5, 'is_active' => false]);
        $this->actingAs($owner);

        $this->postJson('/api/v1/subscription/coupon', ['code' => ' maistempo '])->assertOk()
            ->assertJsonPath('data.trial_ends_at', $trialEnds->copy()->addDays(15)->toIso8601String());
        $this->postJson('/api/v1/subscription/coupon', ['code' => 'MAISTEMPO'])->assertUnprocessable()->assertJsonPath('code', 'COUPON_ALREADY_USED');
        $this->postJson('/api/v1/subscription/coupon', ['code' => 'DESCONTO'])->assertUnprocessable()->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
        $this->postJson('/api/v1/subscription/coupon', ['code' => 'VELHO'])->assertUnprocessable()->assertJsonPath('code', 'COUPON_EXPIRED');
        $this->postJson('/api/v1/subscription/coupon', ['code' => 'FUTURO'])->assertUnprocessable()->assertJsonPath('code', 'COUPON_NOT_STARTED');
        $this->postJson('/api/v1/subscription/coupon', ['code' => 'DESLIGADO'])->assertUnprocessable()->assertJsonPath('code', 'COUPON_INVALID');
        $this->postJson('/api/v1/subscription/quote', $this->body('start', 'monthly', ['intent' => 'order', 'coupon_code' => 'FUTURO']))
            ->assertUnprocessable()->assertJsonPath('code', 'COUPON_NOT_STARTED');

        // Depois de expirar (e antes da primeira ativação), um cupão de dias devolve o acesso.
        [$ownerB, $tenantB] = $this->createCompany('Empresa B');
        $this->sub($tenantB)->forceFill(['trial_ends_at' => now()->subDays(2)])->save();
        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();
        $this->assertSame(TenantStatus::ReadOnly, $tenantB->refresh()->status);
        $this->actingAs($ownerB)->postJson('/api/v1/subscription/coupon', ['code' => 'MAISTEMPO'])->assertOk()->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_ends_at', now()->addDays(15)->toIso8601String());
        $this->assertSame(TenantStatus::Active, $tenantB->refresh()->status);
    }

    public function test_custom_terms_stay_with_the_platform_and_unpaid_orders_expire(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->sub($tenant)->forceFill(['custom_limits' => ['max_users' => 40]])->save();
        $this->actingAs($owner)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonPath('data.custom_terms', true);
        $this->postJson('/api/v1/subscription/quote', $this->body('business') + ['intent' => 'change'])->assertConflict()->assertJsonPath('code', 'SUBSCRIPTION_CUSTOM');
        // Ativa sem fim de período (combinada à mão, como a empresa de demonstração) também.
        $this->sub($tenant)->forceFill(['custom_limits' => null, 'status' => 'active', 'trial_ends_at' => null, 'current_period_ends_at' => null])->save();
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.custom_terms', true);
        $this->postJson('/api/v1/subscription/orders', $this->body('business'))->assertConflict()->assertJsonPath('code', 'SUBSCRIPTION_CUSTOM');

        [$ownerB, $tenantB] = $this->createCompany('Empresa B');
        $this->coupon(['code' => 'PRIMEIRO', 'percent_off' => 50, 'max_redemptions' => 1]);
        $order = $this->actingAs($ownerB)->postJson('/api/v1/subscription/orders', $this->body('start', 'monthly', ['coupon_code' => 'PRIMEIRO']))->assertCreated()->json('data.id');
        $this->travel(31)->days();
        $this->artisan('obraconnect:subscriptions-sweep')->assertSuccessful();
        $this->assertSame('expired', SubscriptionOrder::query()->find($order)->status);
        $this->assertSame(0, CouponRedemption::query()->count());
    }

    public function test_billing_manages_coupons(): void
    {
        $billing = $this->platformUser(PlatformRole::Billing);
        $this->actingAs($this->platformUser(PlatformRole::Support))->getJson('/api/v1/platform/coupons')->assertForbidden();
        $this->actingAs($billing);

        $this->postJson('/api/v1/platform/coupons', ['code' => 'DOIS', 'percent_off' => 10, 'trial_extension_days' => 5, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('percent_off');
        $this->postJson('/api/v1/platform/coupons', ['code' => 'NADA', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('percent_off');
        $this->postJson('/api/v1/platform/coupons', ['code' => 'com espaço', 'percent_off' => 10, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
        $id = $this->postJson('/api/v1/platform/coupons', ['code' => ' natal-2026 ', 'percent_off' => 10, 'billing_cycles' => ['yearly'], 'valid_until' => '2026-12-31', 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.code', 'NATAL-2026')->assertJsonPath('data.summary', '10 % de desconto')->json('data.id');
        $this->assertSame('2026-12-31 23:59:59', Coupon::query()->find($id)->valid_until->timezone('Europe/Lisbon')->format('Y-m-d H:i:s'));
        $this->postJson('/api/v1/platform/coupons', ['code' => 'NATAL-2026', 'percent_off' => 5, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');

        [$owner] = $this->createCompany();
        $this->actingAs($owner)->postJson('/api/v1/subscription/orders', $this->body('start', 'yearly', ['coupon_code' => 'NATAL-2026']))->assertCreated();
        $this->actingAs($billing)->putJson("/api/v1/platform/coupons/{$id}", ['code' => 'NATAL-2026', 'percent_off' => 50, 'is_active' => true])
            ->assertConflict()->assertJsonPath('code', 'COUPON_IN_USE');
        $this->putJson("/api/v1/platform/coupons/{$id}", ['code' => 'NATAL-2026', 'percent_off' => 10, 'billing_cycles' => ['yearly'], 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.redemptions_count', 1);
        $this->getJson('/api/v1/platform/coupons')->assertOk()->assertJsonPath('data.0.code', 'NATAL-2026');
        $this->assertNotNull(User::query()->find($billing->id));
    }
}
