<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    protected function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Associação do utilizador autenticado na empresa ativa (nula em sessões de suporte). */
    protected function membership(): ?Membership
    {
        return $this->tenantContext()->membership();
    }
}
