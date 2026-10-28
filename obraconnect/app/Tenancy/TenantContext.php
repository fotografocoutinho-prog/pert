<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Context;

/**
 * Empresa ativa no pedido ou trabalho em fila atual.
 *
 * Registado como "scoped": é reposto entre pedidos e entre trabalhos da fila.
 * O ID da empresa é também escrito no Context do Laravel, que viaja com os
 * trabalhos em fila e é restaurado em AppServiceProvider.
 */
class TenantContext
{
    public const CONTEXT_KEY = 'obraconnect.tenant_id';

    private ?Tenant $tenant = null;

    private ?Membership $membership = null;

    private bool $readOnly = false;

    private ?SupportAccessGrant $supportGrant = null;

    private int $bypassDepth = 0;

    public function set(
        Tenant $tenant,
        ?Membership $membership = null,
        bool $readOnly = false,
        ?SupportAccessGrant $supportGrant = null,
    ): void {
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->readOnly = $readOnly;
        $this->supportGrant = $supportGrant;

        Context::addHidden(self::CONTEXT_KEY, $tenant->getKey());
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
        $this->readOnly = false;
        $this->supportGrant = null;

        Context::forgetHidden(self::CONTEXT_KEY);
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantOrFail(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContextException('Sem empresa no contexto.');
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function supportGrant(): ?SupportAccessGrant
    {
        return $this->supportGrant;
    }

    public function isSupportSession(): bool
    {
        return $this->supportGrant !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Corre código como se a empresa indicada estivesse ativa (sem associação de utilizador).
     * Usado no registo de empresas, em comandos e em trabalhos de plataforma.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = [$this->tenant, $this->membership, $this->readOnly, $this->supportGrant];

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            [$tenant, $membership, $readOnly, $grant] = $previous;
            $tenant ? $this->set($tenant, $membership, $readOnly, $grant) : $this->clear();
        }
    }

    /**
     * Desativa o filtro por empresa. Só para operações globais de plataforma
     * (estatísticas, varrimento de subscrições). Cada uso deve ser óbvio em revisão.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }
}
