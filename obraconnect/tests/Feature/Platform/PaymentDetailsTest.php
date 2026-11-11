<?php

namespace Tests\Feature\Platform;

use App\Billing\PaymentDetails;
use App\Enums\PlatformRole;
use App\Mail\SubscriptionNoticeMail;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentDetailsTest extends TestCase
{
    // NIB e IBAN fictícios, com os algarismos de controlo certos.
    private const NIB = '0000 0000 1234 5678 9013 5';

    private const IBAN = 'PT50000000001234567890135';

    private const OTHER_IBAN = 'PT50000000009876543210992';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_iban_rules(): void
    {
        $this->assertSame(self::IBAN, PaymentDetails::normalizeIban(self::NIB));
        $this->assertSame(self::IBAN, PaymentDetails::normalizeIban('pt50 0000.0000-1234 5678 9013 5'));
        $this->assertNull(PaymentDetails::normalizeIban('   '));
        $this->assertTrue(PaymentDetails::validIban(self::IBAN));
        $this->assertTrue(PaymentDetails::validIban(self::OTHER_IBAN));
        $this->assertTrue(PaymentDetails::validIban('DE89370400440532013000'));
        $this->assertTrue(PaymentDetails::validIban('GB29NWBK60161331926819'));
        $this->assertFalse(PaymentDetails::validIban('PT50000000001234567890136'), 'algarismo de controlo errado');
        $this->assertFalse(PaymentDetails::validIban('PT5000000000123456789013'), 'IBAN português curto');
        $this->assertFalse(PaymentDetails::validIban('1234'));
        $this->assertSame('PT50 0000 0000 1234 5678 9013 5', PaymentDetails::format(self::IBAN));
        $this->assertSame('PT50 … 0135', PaymentDetails::mask(self::IBAN));
    }

    public function test_super_admin_saves_a_nib_as_iban_and_the_billing_team_is_warned(): void
    {
        $admin = $this->platformUser();
        $billing = $this->platformUser(PlatformRole::Billing);
        $this->actingAs($admin);

        $this->getJson('/api/v1/platform/payment-details')->assertOk()
            ->assertJsonPath('data.iban', null)->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.instructions', config('obraconnect.billing.payment_instructions'))
            ->assertJsonPath('data.preview.transfer', false);

        $this->putJson('/api/v1/platform/payment-details', [
            'account_holder' => 'Construções Exemplo, Lda.', 'iban' => self::NIB, 'bank' => 'Banco Exemplo', 'bic' => 'cgdi ptpl',
            'proof_email' => 'pagamentos@example.test', 'instructions' => null,
        ])->assertOk()
            ->assertJsonPath('data.iban', 'PT50 0000 0000 1234 5678 9013 5')->assertJsonPath('data.bic', 'CGDIPTPL')
            ->assertJsonPath('data.preview.transfer', true)->assertJsonPath('data.updated_by', $admin->name)
            ->assertJsonPath('data.changed', ['account_holder', 'iban', 'bank', 'bic', 'proof_email']);

        $this->assertSame(self::IBAN, PlatformSetting::for(PaymentDetails::KEY)->value['iban']);
        $log = AuditLog::query()->where('event', 'platform.payment_details_updated')->sole();
        $this->assertNull($log->tenant_id);
        // Campo a campo: o MySQL reordena as chaves das colunas JSON.
        $this->assertSame('sem IBAN', $log->properties['iban']['old']);
        $this->assertSame('PT50 … 0135', $log->properties['iban']['new']);
        $this->assertEqualsCanonicalizing(['account_holder', 'iban', 'bank', 'bic', 'proof_email'], $log->properties['changed']);
        $this->assertStringNotContainsString(self::IBAN, json_encode($log->properties));

        foreach ([$admin, $billing] as $staff) {
            Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($staff->email) && $m->subjectLine === 'Dados de pagamento alterados'
                && str_contains(implode(' ', $m->lines), 'agora PT50 … 0135') && ! str_contains(implode(' ', $m->lines), self::IBAN));
        }
        Mail::assertQueuedCount(2);

        // Guardar o mesmo não regista nem avisa; mudar só o texto regista mas não avisa.
        $same = ['account_holder' => 'Construções Exemplo, Lda.', 'iban' => self::IBAN, 'bank' => 'Banco Exemplo', 'bic' => 'CGDIPTPL', 'proof_email' => 'pagamentos@example.test'];
        $this->putJson('/api/v1/platform/payment-details', $same)->assertOk()->assertJsonPath('data.changed', []);
        $this->putJson('/api/v1/platform/payment-details', $same + ['instructions' => 'Pague em 8 dias.'])->assertOk()
            ->assertJsonPath('data.changed', ['instructions'])->assertJsonPath('data.instructions', 'Pague em 8 dias.');
        $this->assertSame(2, AuditLog::query()->where('event', 'platform.payment_details_updated')->count());
        Mail::assertQueuedCount(2);

        // Trocar o IBAN volta a avisar, com o antes e o depois mascarados.
        $this->putJson('/api/v1/platform/payment-details', ['iban' => self::OTHER_IBAN] + $same)->assertOk();
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo($billing->email) && str_contains(implode(' ', $m->lines), 'antes PT50 … 0135, agora PT50 … 0992'));

        // Apagar o IBAN deixa só as indicações.
        $this->putJson('/api/v1/platform/payment-details', ['iban' => '', 'account_holder' => ''])->assertOk()
            ->assertJsonPath('data.iban', null)->assertJsonPath('data.preview.transfer', false)
            ->assertJsonPath('data.instructions', config('obraconnect.billing.payment_instructions'));
    }

    public function test_invalid_details_are_rejected(): void
    {
        $this->actingAs($this->platformUser());

        $this->putJson('/api/v1/platform/payment-details', ['account_holder' => 'X', 'iban' => 'PT50000000001234567890136'])
            ->assertUnprocessable()->assertJsonValidationErrors('iban');
        $this->putJson('/api/v1/platform/payment-details', ['iban' => self::IBAN])
            ->assertUnprocessable()->assertJsonValidationErrors('account_holder');
        $this->putJson('/api/v1/platform/payment-details', ['account_holder' => 'X', 'iban' => self::IBAN, 'bic' => 'ABC', 'proof_email' => 'nao-e-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bic', 'proof_email']);
        $this->assertNull(PlatformSetting::for(PaymentDetails::KEY));
    }

    public function test_billing_sees_but_only_the_super_admin_changes(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner)->getJson('/api/v1/platform/payment-details')->assertForbidden()->assertJsonPath('code', 'PLATFORM_ONLY');
        $this->actingAs($this->platformUser(PlatformRole::Support))->getJson('/api/v1/platform/payment-details')->assertForbidden();

        $this->actingAs($this->platformUser(PlatformRole::Billing));
        $this->getJson('/api/v1/platform/payment-details')->assertOk()->assertJsonPath('data.can_edit', false);
        $this->putJson('/api/v1/platform/payment-details', ['account_holder' => 'X', 'iban' => self::IBAN])
            ->assertForbidden()->assertJsonPath('code', 'PLATFORM_ROLE');
        $this->assertNull(PlatformSetting::for(PaymentDetails::KEY));
    }

    public function test_companies_see_the_transfer_details_on_their_order_and_in_the_email(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        PaymentDetails::save(['account_holder' => 'Construções Exemplo, Lda.', 'iban' => self::IBAN, 'proof_email' => 'pagamentos@example.test'], $this->platformUser());
        [$owner] = $this->createCompany();
        $this->actingAs($owner);

        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.payment.transfer', true)->assertJsonPath('data.payment.iban', 'PT50 0000 0000 1234 5678 9013 5')
            ->assertJsonPath('data.payment.account_holder', 'Construções Exemplo, Lda.')->assertJsonPath('data.payment.bic', null)
            ->assertJsonPath('data.payment_instructions', config('obraconnect.billing.payment_instructions'));

        $plan = Plan::query()->where('code', 'professional')->firstOrFail();
        $order = $this->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertCreated()
            ->assertJsonPath('data.payment_reference', fn ($ref) => str_starts_with($ref, 'ObraConnect '))->json('data.id');
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.orders.0.payment_reference', "ObraConnect {$order}");

        Mail::assertQueued(SubscriptionNoticeMail::class, function ($m) use ($owner, $order) {
            $text = implode("\n", $m->lines);

            return $m->hasTo($owner->email)
                && str_contains($text, 'IBAN PT50 0000 0000 1234 5678 9013 5 (titular Construções Exemplo, Lda.)')
                && str_contains($text, "No descritivo da transferência indique: ObraConnect {$order}.")
                && str_contains($text, 'Envie o comprovativo para pagamentos@example.test.')
                && str_contains($text, (string) config('obraconnect.billing.payment_instructions'));
        });
    }
}
