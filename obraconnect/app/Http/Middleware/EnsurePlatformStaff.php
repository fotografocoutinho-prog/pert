<?php

namespace App\Http\Middleware;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('platform') ou ->middleware('platform:billing,support').
 * O super administrador passa sempre.
 */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user?->isPlatformStaff()) {
            throw ApiException::forbidden('Acesso reservado à equipa da plataforma.', 'PLATFORM_ONLY');
        }

        if (config('obraconnect.security.platform_requires_mfa') && ! $user->hasTwoFactorEnabled()) {
            throw ApiException::forbidden('A equipa da plataforma tem de usar autenticação de dois fatores.', 'MFA_REQUIRED');
        }

        $allowed = $roles === []
            || $user->platform_role === PlatformRole::SuperAdmin
            || in_array($user->platform_role->value, $roles, true);

        if (! $allowed) {
            throw ApiException::forbidden('O seu papel na plataforma não permite esta ação.', 'PLATFORM_ROLE');
        }

        return $next($request);
    }
}
