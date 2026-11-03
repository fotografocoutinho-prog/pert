<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('obraconnect.security.force_https') && ! $request->isSecure()) {
            // Não usar redirect()->secure(): junta o URI (que já traz a subpasta) à raiz da
            // aplicação (que também a traz) e numa subpasta dava /obraconnect/obraconnect/...
            return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
