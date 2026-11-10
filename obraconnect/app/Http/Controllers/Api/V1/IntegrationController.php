<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Integrations;
use App\Integrations\SafeUrl;
use App\Integrations\Webhooks;
use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Integrações da empresa: chaves da API pública e webhooks. As chaves e os segredos só se mostram
 * uma vez, quando são criados.
 */
class IntegrationController extends Controller
{
    public const MAX_KEYS = 20;

    public const MAX_WEBHOOKS = 10;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        $this->authorize('integrations.manage');
        $tenant = $this->tenantContext()->tenantOrFail();

        return response()->json(['data' => [
            'enabled' => Integrations::enabledFor($tenant),
            'base_url' => url('/api/public/v1'),
            'scopes' => collect(Integrations::SCOPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'events' => collect(Integrations::EVENTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'api_keys' => ApiKey::query()->with('createdBy:id,name')->orderByRaw('revoked_at is not null')->latest('id')->limit(50)->get()
                ->map(fn (ApiKey $k) => $this->key($k)),
            'webhooks' => WebhookEndpoint::query()->orderBy('id')->get()->map(fn (WebhookEndpoint $w) => $this->webhook($w)),
        ]]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(Integrations::SCOPES))],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [], ['name' => 'nome', 'scopes' => 'âmbitos', 'expires_at' => 'validade']);
        if (ApiKey::query()->whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            throw ApiException::conflict('Já tem '.self::MAX_KEYS.' chaves ativas. Revogue uma que já não use.', 'API_KEYS_LIMIT');
        }

        do {
            $plain = 'oc_'.Str::random(40);
        } while (ApiKey::query()->withoutGlobalScopes()->where('prefix', substr($plain, 0, 12))->exists());

        $key = new ApiKey([
            'name' => $data['name'],
            'prefix' => substr($plain, 0, 12),
            'scopes' => array_values(array_unique($data['scopes'])),
            'created_by_user_id' => $request->user()->id,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
        ]);
        $key->key_hash = hash('sha256', $plain);
        $key->save();
        $this->auditor->record('integrations.key_created', $key, ['name' => $key->name, 'scopes' => $key->scopes]);

        return response()->json(['data' => $this->key($key->load('createdBy:id,name')) + ['plain_key' => $plain]], 201);
    }

    public function revokeKey(ApiKey $apiKey): Response
    {
        $this->authorize('integrations.manage');
        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
            $this->auditor->record('integrations.key_revoked', $apiKey, ['name' => $apiKey->name]);
        }

        return response()->noContent();
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $data = $this->validateWebhook($request);
        if (WebhookEndpoint::query()->count() >= self::MAX_WEBHOOKS) {
            throw ApiException::conflict('Já tem '.self::MAX_WEBHOOKS.' webhooks. Apague um que já não use.', 'WEBHOOKS_LIMIT');
        }
        SafeUrl::assert($data['url']);

        $secret = self::newSecret();
        $webhook = WebhookEndpoint::query()->create([
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'events' => $data['events'],
            'secret' => $secret,
            'active' => true,
            'created_by_user_id' => $request->user()->id,
        ]);
        $this->auditor->record('integrations.webhook_created', $webhook, ['url' => $webhook->url, 'events' => $webhook->events]);

        return response()->json(['data' => $this->webhook($webhook) + ['secret' => $secret]], 201);
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage();
        $data = $this->validateWebhook($request) + $request->validate(['active' => ['sometimes', 'boolean']]);
        if ($data['url'] !== $webhookEndpoint->url) {
            SafeUrl::assert($data['url']);
        }
        $webhookEndpoint->fill([
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'events' => $data['events'],
        ]);
        if (array_key_exists('active', $data)) {
            $webhookEndpoint->active = (bool) $data['active'];
            if ($webhookEndpoint->active) {
                $webhookEndpoint->forceFill(['consecutive_failures' => 0, 'disabled_reason' => null]);
            }
        }
        $webhookEndpoint->save();
        $this->auditor->record('integrations.webhook_updated', $webhookEndpoint, ['url' => $webhookEndpoint->url, 'events' => $webhookEndpoint->events, 'active' => $webhookEndpoint->active]);

        return response()->json(['data' => $this->webhook($webhookEndpoint)]);
    }

    public function destroyWebhook(WebhookEndpoint $webhookEndpoint): Response
    {
        $this->authorize('integrations.manage');
        $this->auditor->record('integrations.webhook_deleted', $webhookEndpoint, ['url' => $webhookEndpoint->url]);
        $webhookEndpoint->delete();

        return response()->noContent();
    }

    public function rotateSecret(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage();
        $secret = self::newSecret();
        $webhookEndpoint->forceFill(['secret' => $secret])->save();
        $this->auditor->record('integrations.webhook_secret_rotated', $webhookEndpoint, ['url' => $webhookEndpoint->url]);

        return response()->json(['data' => $this->webhook($webhookEndpoint) + ['secret' => $secret]]);
    }

    /** Envia já um evento "ping" (uma tentativa, sem repetições) e devolve o resultado. */
    public function test(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage();
        $tenant = $this->tenantContext()->tenantOrFail();
        $id = (string) Str::uuid();
        $delivery = $webhookEndpoint->deliveries()->create([
            'event' => 'ping',
            'event_id' => $id,
            'payload' => [
                'id' => $id,
                'event' => 'ping',
                'created_at' => now()->toIso8601String(),
                'company' => ['id' => $tenant->id, 'name' => $tenant->name],
                'data' => ['message' => 'Teste enviado a partir do ObraConnect.'],
            ],
            'status' => 'pending',
        ]);
        [$status, $body, $error] = DeliverWebhook::send($webhookEndpoint, $delivery);
        $delivery->forceFill([
            'status' => $error === null ? 'delivered' : 'failed',
            'attempts' => 1,
            'response_status' => $status,
            'response_body' => $body,
            'error' => $error,
            'delivered_at' => $error === null ? now() : null,
        ])->save();
        if ($error === null) {
            $webhookEndpoint->forceFill(['last_delivery_at' => now()])->save();
        }

        return response()->json(['data' => $this->delivery($delivery)]);
    }

    public function deliveries(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorize('integrations.manage');

        return response()->json(['data' => $webhookEndpoint->deliveries()->latest('id')->limit(50)->get()
            ->map(fn (WebhookDelivery $d) => $this->delivery($d))]);
    }

    /** Volta a enviar um evento (como nova entrega, com o mesmo id do evento para o recetor poder ignorar repetidos). */
    public function retry(WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery): JsonResponse
    {
        $this->authorizeManage();
        if (! $webhookEndpoint->active) {
            throw ApiException::conflict('Ligue o webhook antes de voltar a enviar.', 'WEBHOOK_DISABLED');
        }
        if ($delivery->status === 'pending') {
            throw ApiException::conflict('Esta entrega ainda está a ser tentada.', 'WEBHOOK_DELIVERY_PENDING');
        }
        $copy = $webhookEndpoint->deliveries()->create([
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'payload' => $delivery->payload,
            'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($copy->id, $this->tenantContext()->tenantOrFail()->id)->afterCommit();

        return response()->json(['data' => $this->delivery($copy->refresh())], 202);
    }

    // --- interno ---

    private function authorizeManage(): void
    {
        $this->authorize('integrations.manage');
        Integrations::assertEnabled($this->tenantContext()->tenantOrFail());
    }

    /** @return array{url: string, description: ?string, events: list<string>} */
    private function validateWebhook(Request $request): array
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:https,http'],
            'description' => ['nullable', 'string', 'max:150'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(Integrations::EVENTS))],
        ], [], ['url' => 'endereço', 'description' => 'descrição', 'events' => 'eventos']);
        $data['events'] = array_values(array_unique($data['events']));

        return $data;
    }

    private static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    private function key(ApiKey $k): array
    {
        return [
            'id' => $k->id,
            'name' => $k->name,
            'prefix' => $k->prefix,
            'scopes' => $k->scopes,
            'created_by' => $k->createdBy?->name,
            'created_at' => $k->created_at?->toIso8601String(),
            'last_used_at' => $k->last_used_at?->toIso8601String(),
            'last_used_ip' => $k->last_used_ip,
            'expires_at' => $k->expires_at?->toIso8601String(),
            'revoked_at' => $k->revoked_at?->toIso8601String(),
            'usable' => $k->isUsable(),
        ];
    }

    private function webhook(WebhookEndpoint $w): array
    {
        $since = now()->subDay();

        return [
            'id' => $w->id,
            'url' => $w->url,
            'description' => $w->description,
            'events' => $w->events,
            'active' => $w->active,
            'consecutive_failures' => $w->consecutive_failures,
            'disabled_reason' => $w->disabled_reason,
            'last_delivery_at' => $w->last_delivery_at?->toIso8601String(),
            'created_at' => $w->created_at?->toIso8601String(),
            'last_24h' => [
                'delivered' => $w->deliveries()->where('created_at', '>=', $since)->where('status', 'delivered')->count(),
                'failed' => $w->deliveries()->where('created_at', '>=', $since)->where('status', 'failed')->count(),
                'pending' => $w->deliveries()->where('status', 'pending')->count(),
            ],
        ];
    }

    private function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'event' => $d->event,
            'event_id' => $d->event_id,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_status' => $d->response_status,
            'response_body' => $d->response_body,
            'error' => $d->error,
            'created_at' => $d->created_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'payload' => $d->payload,
        ];
    }
}
