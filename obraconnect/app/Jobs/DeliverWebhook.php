<?php

namespace App\Jobs;

use App\Integrations\SafeUrl;
use App\Integrations\Webhooks;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entrega de um evento a um webhook. Sem resposta 2xx, volta a tentar mais tarde (1 min, 5 min, 30 min,
 * 2 h, 6 h); à 6.ª falha desiste. Dez entregas falhadas seguidas desligam o webhook.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public const DISABLE_AFTER = 10;

    public int $tries = 6;

    public function __construct(public readonly int $deliveryId, public readonly int $tenantId) {}

    public function handle(TenantContext $tenants): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $tenants->runAs($tenant, function (): void {
            $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
            if ($delivery === null || $delivery->status === 'delivered') {
                return;
            }
            $endpoint = $delivery->endpoint;
            if ($endpoint === null || ! $endpoint->active) {
                $delivery->forceFill(['status' => 'failed', 'error' => 'O webhook está desligado.', 'next_attempt_at' => null])->save();

                return;
            }

            [$status, $responseBody, $error] = self::send($endpoint, $delivery);
            $attempts = $delivery->attempts + 1;
            if ($error === null) {
                $delivery->forceFill(['status' => 'delivered', 'attempts' => $attempts, 'response_status' => $status, 'response_body' => $responseBody,
                    'error' => null, 'delivered_at' => now(), 'next_attempt_at' => null])->save();
                $endpoint->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => now()])->save();

                return;
            }

            $delay = self::BACKOFF[$attempts - 1] ?? null;
            $delivery->forceFill([
                'status' => $delay === null ? 'failed' : 'pending',
                'attempts' => $attempts,
                'response_status' => $status,
                'response_body' => $responseBody,
                'error' => $error,
                'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
            ])->save();

            if ($delay === null) {
                $failures = $endpoint->consecutive_failures + 1;
                $endpoint->forceFill(['consecutive_failures' => $failures] + ($failures >= self::DISABLE_AFTER
                    ? ['active' => false, 'disabled_reason' => "Desligado sozinho depois de {$failures} entregas falhadas seguidas."] : []))->save();
            } else {
                // Volta à fila para mais tarde (sem lançar a exceção: com a fila síncrona estragaria a ação de origem).
                $this->release($delay);
            }
        });
    }

    /**
     * Uma tentativa de envio, assinada. Devolve [estado HTTP, início da resposta, erro ou null se correu bem].
     *
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    public static function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): array
    {
        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        try {
            $ips = SafeUrl::assert($endpoint->url);
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withOptions(SafeUrl::pinnedOptions($endpoint->url, $ips))->withHeaders([
                'User-Agent' => 'ObraConnect-Webhooks/1',
                'X-ObraConnect-Event' => $delivery->event,
                'X-ObraConnect-Delivery' => $delivery->event_id,
                'X-ObraConnect-Timestamp' => (string) $timestamp,
                'X-ObraConnect-Signature' => Webhooks::signature($endpoint->secret, $timestamp, $body),
            ])->withBody($body, 'application/json')->post($endpoint->url);
            $status = $response->status();

            return [$status, Str::limit((string) $response->body(), 1000), $response->successful() ? null : "Resposta HTTP {$status}"];
        } catch (Throwable $e) {
            return [null, null, Str::limit($e->getMessage(), 500)];
        }
    }
}
