<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Models\Partner;
use App\Models\PartnerPortalUser;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal de subempreiteiros: o {access} da rota tem de ser um acesso ativo do próprio utilizador.
 * Põe a empresa no contexto (sem associação: o utilizador não é membro) e deixa o acesso disponível em
 * $request->attributes('portal_access'). Tudo o resto filtra pelo subempreiteiro desse acesso.
 */
class ResolvePortalAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $id = $request->route('access');

        $access = ctype_digit((string) $id) ? PartnerPortalUser::query()->withoutGlobalScope(TenantScope::class)
            ->whereKey((int) $id)->where('user_id', $user->id)->whereNotNull('accepted_at')->whereNull('revoked_at')
            ->with('tenant')->first() : null;
        $partner = $access ? Partner::query()->withoutGlobalScope(TenantScope::class)->whereKey($access->partner_id)->where('tenant_id', $access->tenant_id)->first() : null;

        if ($access === null || $partner === null || $partner->archived_at !== null || $access->tenant === null) {
            throw new ApiException('Acesso ao portal não encontrado ou já sem efeito.', 'PORTAL_ACCESS_INVALID', 404);
        }
        if ($access->tenant->status === TenantStatus::Suspended) {
            throw new ApiException('O portal desta empresa está indisponível.', 'TENANT_SUSPENDED', 423);
        }

        // Em só leitura (subscrição expirada) o portal consulta, mas não submete.
        $readOnly = $access->tenant->status === TenantStatus::ReadOnly;
        if ($readOnly && ! $request->isMethodSafe()) {
            throw new ApiException('O portal desta empresa está em modo só leitura.', 'TENANT_READ_ONLY', 423);
        }

        $this->context->set($access->tenant, null, $readOnly);
        if ($access->last_seen_at === null || $access->last_seen_at->lt(now()->subMinutes(10))) {
            $access->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        $request->attributes->set('portal_access', $access);
        $request->attributes->set('portal_partner', $partner);

        return $next($request);
    }
}
