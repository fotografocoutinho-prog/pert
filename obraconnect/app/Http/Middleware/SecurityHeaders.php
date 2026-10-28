<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; worker-src 'self'; "
        ."manifest-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Câmara e microfone ficam disponíveis para a própria app (fotografias e voz em obra).
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Em desenvolvimento com o servidor Vite a correr, a CSP bloquearia o HMR.
        if (config('obraconnect.security.content_security_policy')
            && ! $headers->has('Content-Security-Policy')
            && ! app(Vite::class)->isRunningHot()) {
            $headers->set('Content-Security-Policy', self::CSP);
        }

        return $response;
    }
}
