<?php

namespace App\Http\Middleware;

use App\Audit\Auditor;
use App\Enums\PlatformRole;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Monta o contexto da empresa ativa. Tem de correr depois da autenticação e antes
 * do route model binding (ver prioridade em bootstrap/app.php).
 */
class ResolveTenant
{
    public const SUPPORT_SESSION_KEY = 'support_grant_id';

    /** Rotas que continuam disponíveis com a empresa em modo só leitura. */
    private const READ_ONLY_ALLOWED_ROUTES = ['api.v1.exports.store'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;

        if ($user->isPlatformStaff() && $request->hasSession() && $request->session()->has(self::SUPPORT_SESSION_KEY)) {
            return $this->handleSupportSession($request, $next, $user);
        }

        $membership = $this->resolver->resolveMembership($user)
            ?? throw ApiException::forbidden('Não tem acesso a nenhuma empresa ativa.', 'NO_TENANT');

        $tenant = $membership->tenant;

        if ($tenant->status === TenantStatus::Suspended) {
            throw new ApiException('A empresa está suspensa. Contacte o suporte.', 'TENANT_SUSPENDED', 423);
        }

        if ($tenant->requiresMfa() && ! $user->hasTwoFactorEnabled()) {
            throw ApiException::forbidden('A sua empresa exige autenticação de dois fatores. Ative-a em "A minha conta".', 'MFA_REQUIRED');
        }

        $readOnly = $tenant->status === TenantStatus::ReadOnly;
        $this->context->set($tenant, $membership, $readOnly);

        if ($readOnly && ! $request->isMethodSafe() && ! $request->routeIs(...self::READ_ONLY_ALLOWED_ROUTES)) {
            throw new ApiException(
                'A empresa está em modo só leitura porque a subscrição expirou. Pode consultar e exportar os dados.',
                'TENANT_READ_ONLY',
                423,
            );
        }

        return $next($request);
    }

    private function handleSupportSession(Request $request, Closure $next, User $user): Response
    {
        $grant = SupportAccessGrant::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->find($request->session()->get(self::SUPPORT_SESSION_KEY));

        if ($grant === null || ! $grant->isActive() || $grant->tenant === null
            || ! $user->hasPlatformRole(PlatformRole::SuperAdmin, PlatformRole::Support)) {
            $request->session()->forget(self::SUPPORT_SESSION_KEY);

            throw ApiException::forbidden('A autorização de suporte expirou ou foi revogada.', 'SUPPORT_GRANT_INVALID');
        }

        $this->context->set($grant->tenant, null, true, $grant);

        if (! $request->isMethodSafe()) {
            throw ApiException::forbidden('O acesso do suporte técnico é só de leitura.', 'SUPPORT_READ_ONLY');
        }

        if ($grant->last_used_at === null || $grant->last_used_at->lt(now()->subMinute())) {
            $grant->forceFill(['last_used_at' => now(), 'last_used_by_user_id' => $user->getKey()])->saveQuietly();
        }

        $this->auditor->record('support.request', $grant, [
            'method' => $request->method(),
            'path' => $request->path(),
        ], actor: $user, actorType: 'support');

        return $next($request);
    }
}
