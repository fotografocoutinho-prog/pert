<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pessoa de um subempreiteiro com acesso ao portal (só aos contratos desse subempreiteiro com a
 * empresa). Não é membro da empresa.
 */
class PartnerPortalUser extends Model
{
    use BelongsToTenant;

    /** Validade do convite. */
    public const INVITATION_DAYS = 14;

    protected $guarded = ['id', 'tenant_id', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** O token em claro é o segredo enviado por email; procura-se em qualquer empresa. */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope(TenantScope::class)->where('token_hash', static::hashToken($token))->first();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->accepted_at !== null => 'active',
            $this->expires_at === null || $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }
}
