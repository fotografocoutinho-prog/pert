<?php

namespace App\Http\Middleware;

use App\Access\AccessResolver;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeiro middleware de cada pedido:
 * - garante que nenhum contexto de empresa ou cache de permissões transita de um
 *   pedido anterior (relevante em testes e em runtimes persistentes);
 * - atribui um identificador ao pedido, gravado nos logs e na auditoria e
 *   devolvido no cabeçalho X-Request-Id.
 */
class InitializeRequestContext
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AccessResolver $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenants->clear();
        $this->access->flush();

        $id = $request->headers->get('X-Request-Id');

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9\-]{8,36}$/', $id)) {
            $id = (string) Str::uuid();
        }

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
