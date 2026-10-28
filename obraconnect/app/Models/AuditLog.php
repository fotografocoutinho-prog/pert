<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Registo de auditoria append-only.
 *
 * Não usa BelongsToTenant porque a plataforma também regista eventos sem empresa
 * (logins, ações de backoffice). As consultas de empresa usam sempre forTenant().
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registos de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Registos de auditoria não podem ser apagados.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }
}
