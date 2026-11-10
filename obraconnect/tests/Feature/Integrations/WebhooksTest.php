<?php

namespace Tests\Feature\Integrations;

use App\Actions\Tenancy\RegisterCompany;
use App\Integrations\Webhooks;
use App\Jobs\DeliverWebhook;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    // IP público (literal, para os testes não dependerem do DNS).
    private const URL = 'https://93.184.215.14/obraconnect';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('obraconnect.files.disk'));
        config(['obraconnect.docintel.url' => null, 'obraconnect.docintel.key' => null]);
    }

    /** @return array{0: User, 1: Tenant} */
    private function company(string $name = 'Empresa A'): array
    {
        $owner = User::factory()->create();
        $tenant = app(RegisterCompany::class)->handle($owner, ['name' => $name], Plan::query()->where('code', 'business')->firstOrFail());

        return [$owner->refresh(), $tenant];
    }

    private function webhook(User $owner, array $events, string $url = self::URL): array
    {
        return $this->actingAs($owner)->postJson('/api/v1/integrations/webhooks', ['url' => $url, 'events' => $events, 'description' => 'ERP'])
            ->assertCreated()->assertJsonPath('data.active', true)->json('data');
    }

    private static function signed(HttpRequest $r, string $secret): bool
    {
        $ts = $r->header('X-ObraConnect-Timestamp')[0] ?? '';

        return ($r->header('X-ObraConnect-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), $secret);
    }

    public function test_webhook_addresses_must_be_public_https(): void
    {
        [$owner] = $this->company();
        $this->actingAs($owner);
        $post = fn (string $url, array $events = ['alert.opened']) => $this->postJson('/api/v1/integrations/webhooks', ['url' => $url, 'events' => $events]);

        $post('http://93.184.215.14/x')->assertUnprocessable()->assertJsonPath('code', 'WEBHOOK_URL_INSECURE');
        foreach (['https://localhost/x', 'https://servidor.local/x', 'https://127.0.0.1/x', 'https://10.1.2.3/x', 'https://192.168.1.10/x',
            'https://169.254.169.254/latest/meta-data', 'https://100.64.0.1/x', 'https://[::1]/x', 'https://0.0.0.0/x'] as $url) {
            $post($url)->assertUnprocessable()->assertJsonPath('code', 'WEBHOOK_URL_PRIVATE');
        }
        $post('https://eu:segredo@93.184.215.14/x')->assertUnprocessable()->assertJsonPath('code', 'WEBHOOK_URL_INVALID');
        $post('ftp://93.184.215.14/x')->assertUnprocessable()->assertJsonValidationErrors('url');
        $post(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors('events');
        $post(self::URL, ['tudo'])->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $this->assertSame(0, WebhookEndpoint::query()->withoutGlobalScopes()->count());

        $hook = $this->webhook($owner, ['alert.opened', 'alert.opened']);
        $this->assertStringStartsWith('whsec_', $hook['secret']);
        $this->assertSame(['alert.opened'], $hook['events']);
        $index = $this->getJson('/api/v1/integrations')->assertOk()->assertJsonCount(1, 'data.webhooks');
        $this->assertStringNotContainsString($hook['secret'], $index->getContent());
        $this->putJson("/api/v1/integrations/webhooks/{$hook['id']}", ['url' => 'https://10.0.0.1/x', 'events' => ['alert.opened']])
            ->assertUnprocessable()->assertJsonPath('code', 'WEBHOOK_URL_PRIVATE');
    }

    public function test_events_are_signed_and_delivered_to_the_webhooks_that_listen(): void
    {
        config(['obraconnect.docintel.url' => 'https://docintel.test', 'obraconnect.docintel.key' => 'dik_teste']);
        Http::fake([
            'docintel.test/*' => Http::response(['detail' => 'erro'], 500),
            '93.184.215.14/*' => Http::response('recebido', 200),
            '93.184.215.15/*' => Http::response('recebido', 200),
        ]);
        [$owner, $tenant] = $this->company('Construções Alfa');
        $hook = $this->webhook($owner, ['supplier_document.read']);
        $this->webhook($owner, ['alert.opened'], 'https://93.184.215.15/outro');
        [$ownerB] = $this->company('Construções Beta');
        $this->webhook($ownerB, ['supplier_document.read'], 'https://93.184.215.15/beta');

        $pdf = UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n% teste\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
        $doc = $this->actingAs($owner)->post('/api/v1/supplier-documents', ['file' => $pdf], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'to_review')->json('data.id');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL
            && $r->header('X-ObraConnect-Event')[0] === 'supplier_document.read'
            && $r->header('Content-Type')[0] === 'application/json'
            && self::signed($r, $hook['secret'])
            && $r['event'] === 'supplier_document.read'
            && $r['company']['name'] === 'Construções Alfa'
            && $r['data']['id'] === $doc
            && $r['data']['read_error'] === true
            && $r['id'] === $r->header('X-ObraConnect-Delivery')[0]);
        // Nem o webhook de outro evento nem o da outra empresa recebem nada.
        Http::assertNotSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://93.184.215.15/'));

        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$tenant->id, 'delivered', 1, 200], [$delivery->tenant_id, $delivery->status, $delivery->attempts, $delivery->response_status]);
        $this->assertNotNull(WebhookEndpoint::query()->withoutGlobalScopes()->find($hook['id'])->last_delivery_at);
    }

    public function test_failed_deliveries_are_retried_and_repeated_failures_switch_the_webhook_off(): void
    {
        Http::fake(['93.184.215.14/*' => Http::response('erro', 500)]);
        [$owner, $tenant] = $this->company();
        $hook = $this->webhook($owner, ['invoice_draft.ready']);
        $tenants = app(TenantContext::class);
        $emit = fn () => $tenants->runAs($tenant, fn () => Webhooks::dispatch('invoice_draft.ready', ['id' => 1]));
        $runAgain = function (WebhookDelivery $d, int $times) use ($tenant, $tenants): void {
            for ($i = 0; $i < $times; $i++) {
                (new DeliverWebhook($d->id, $tenant->id))->handle($tenants);
            }
        };

        $emit();
        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['pending', 1, 500, 'Resposta HTTP 500'], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error]);
        $this->assertEqualsWithDelta(now()->addMinute()->getTimestamp(), $delivery->next_attempt_at->getTimestamp(), 5);

        $runAgain($delivery, 4);
        $this->assertSame(['pending', 5], [$delivery->refresh()->status, $delivery->attempts]);
        $this->assertEqualsWithDelta(now()->addHours(6)->getTimestamp(), $delivery->next_attempt_at->getTimestamp(), 5);
        $runAgain($delivery, 1);
        $this->assertSame(['failed', 6, null], [$delivery->refresh()->status, $delivery->attempts, $delivery->next_attempt_at]);
        $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->find($hook['id']);
        $this->assertSame([1, true], [$endpoint->consecutive_failures, $endpoint->active]);

        // À 10.ª entrega falhada seguida, desliga-se; desligado, já não recebe eventos.
        $endpoint->forceFill(['consecutive_failures' => 9])->save();
        $emit();
        $runAgain(WebhookDelivery::query()->withoutGlobalScopes()->latest('id')->first(), 5);
        $endpoint->refresh();
        $this->assertFalse($endpoint->active);
        $this->assertStringContainsString('10 entregas falhadas', $endpoint->disabled_reason);
        $emit();
        $this->assertSame(2, WebhookDelivery::query()->withoutGlobalScopes()->count());

        // Voltar a ligar recomeça a contagem.
        $this->actingAs($owner)->putJson("/api/v1/integrations/webhooks/{$hook['id']}", ['url' => self::URL, 'events' => ['invoice_draft.ready'], 'active' => true])
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.consecutive_failures', 0)->assertJsonPath('data.disabled_reason', null);
    }

    public function test_test_ping_resend_secret_rotation_and_isolation(): void
    {
        Http::fake(['93.184.215.14/*' => Http::sequence()->push('ok', 200)->push('em baixo', 503)->push('ok', 200)->push('ok', 200)]);
        [$owner] = $this->company();
        $hook = $this->webhook($owner, ['alert.opened']);
        $base = "/api/v1/integrations/webhooks/{$hook['id']}";

        $this->postJson("{$base}/test")->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.event', 'ping')->assertJsonPath('data.response_status', 200);
        $failed = $this->postJson("{$base}/test")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'Resposta HTTP 503')->json('data');
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-ObraConnect-Event')[0] === 'ping' && self::signed($r, $hook['secret']));

        $copy = $this->postJson("{$base}/deliveries/{$failed['id']}/retry")->assertAccepted()->json('data');
        $this->assertSame($failed['event_id'], $copy['event_id']);
        $this->assertSame('delivered', WebhookDelivery::query()->withoutGlobalScopes()->find($copy['id'])->status);

        $secret = $this->postJson("{$base}/secret")->assertOk()->json('data.secret');
        $this->assertNotSame($hook['secret'], $secret);
        $this->postJson("{$base}/test")->assertOk()->assertJsonPath('data.status', 'delivered');
        $last = Http::recorded()->last()[0];
        $this->assertTrue(self::signed($last, $secret));
        $this->assertFalse(self::signed($last, $hook['secret']));

        $this->getJson("{$base}/deliveries")->assertOk()->assertJsonCount(4, 'data');

        // Outra empresa não vê nem mexe neste webhook.
        [$other] = $this->company('Empresa B');
        $this->actingAs($other)->getJson("{$base}/deliveries")->assertNotFound();
        $this->postJson("{$base}/test")->assertNotFound();
        $this->deleteJson($base)->assertNotFound();

        $this->actingAs($owner)->deleteJson($base)->assertNoContent();
        $this->assertSame(0, WebhookDelivery::query()->withoutGlobalScopes()->count());
    }

    public function test_old_finished_deliveries_are_pruned(): void
    {
        Http::fake(['93.184.215.14/*' => Http::response('ok', 200)]);
        [$owner, $tenant] = $this->company();
        $hook = $this->webhook($owner, ['alert.opened']);
        $this->postJson("/api/v1/integrations/webhooks/{$hook['id']}/test")->assertOk();
        $this->postJson("/api/v1/integrations/webhooks/{$hook['id']}/test")->assertOk();
        $ids = WebhookDelivery::query()->withoutGlobalScopes()->orderBy('id')->pluck('id');
        WebhookDelivery::query()->withoutGlobalScopes()->whereKey($ids[0])->update(['created_at' => now()->subDays(40)]);

        $this->artisan('obraconnect:prune-webhooks')->assertSuccessful();
        $this->assertSame([$ids[1]], WebhookDelivery::query()->withoutGlobalScopes()->pluck('id')->all());
    }
}
