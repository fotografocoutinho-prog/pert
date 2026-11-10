<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Integrations\Integrations;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API pública: "Authorization: Bearer oc_…" (ou "X-API-Key: oc_…"). Põe a empresa da chave no
 * contexto (sem utilizador nem associação) e a chave em $request->attributes('api_key'). Sem sessão.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) ($request->bearerToken() ?: $request->header('X-API-Key', ''));
        $key = str_starts_with($plain, 'oc_') && strlen($plain) >= 20
            ? ApiKey::query()->withoutGlobalScope(TenantScope::class)->where('prefix', substr($plain, 0, 12))->first()
            : null;

        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $plain)) || ! $key->isUsable()) {
            throw new ApiException('Chave de API em falta, inválida ou revogada.', 'API_KEY_INVALID', 401);
        }
        $tenant = Tenant::query()->find($key->tenant_id);
        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            throw new ApiException('A empresa desta chave está suspensa.', 'TENANT_SUSPENDED', 423);
        }
        $readOnly = $tenant->status === TenantStatus::ReadOnly;
        if ($readOnly && ! $request->isMethodSafe()) {
            throw new ApiException('A empresa está em modo só leitura.', 'TENANT_READ_ONLY', 423);
        }
        Integrations::assertEnabled($tenant);

        $this->context->set($tenant, null, $readOnly);
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
