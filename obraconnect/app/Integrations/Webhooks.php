<?php

namespace App\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia um evento aos webhooks da empresa que o escutam. Cada envio fica registado e sai pela fila
 * (depois de a transação acabar), assinado com o segredo do webhook. Uma falha aqui nunca estraga a
 * ação que gerou o evento.
 */
final class Webhooks
{
    /**
     * O conteúdo pode vir numa função: só se calcula se houver webhooks a escutar (e um erro aí também
     * não estraga a ação).
     *
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $data
     */
    public static function dispatch(string $event, array|Closure $data): void
    {
        try {
            $context = app(TenantContext::class);
            $tenant = $context->tenant();
            if ($tenant === null || ! Integrations::enabledFor($tenant)) {
                return;
            }
            $endpoints = WebhookEndpoint::query()->where('active', true)->get()->filter(fn (WebhookEndpoint $e) => $e->listensTo($event));
            if ($endpoints->isEmpty()) {
                return;
            }
            $data = $data instanceof Closure ? $data() : $data;
            foreach ($endpoints as $endpoint) {
                self::queue($endpoint, $event, $data);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $tenant = app(TenantContext::class)->tenantOrFail();
        $id = (string) Str::uuid();
        $delivery = $endpoint->deliveries()->create([
            'event' => $event,
            'event_id' => $id,
            'payload' => [
                'id' => $id,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'company' => ['id' => $tenant->id, 'name' => $tenant->name],
                'data' => $data,
            ],
            'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($delivery->id, $tenant->id)->afterCommit();

        return $delivery;
    }

    /** Assinatura: HMAC-SHA256 de "{timestamp}.{corpo}" com o segredo do webhook. */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
