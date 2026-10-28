<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para todos os modelos com dados de uma empresa.
 *
 * - Filtra automaticamente por tenant_id (TenantScope), falhando fechado sem contexto.
 * - Preenche tenant_id ao criar a partir do contexto.
 * - Recusa criar registos para outra empresa e alterar tenant_id depois de criado.
 *
 * tenant_id nunca deve estar em $fillable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->tenantOrFail()->getKey());

                return;
            }

            if (! $context->isBypassed() && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new LogicException('Tentativa de criar um registo para outra empresa.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id não pode ser alterado.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
