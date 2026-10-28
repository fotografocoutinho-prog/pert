<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'role_id', 'token_hash', 'invited_by_user_id', 'expires_at'])]
class Invitation extends Model
{
    use BelongsToTenant;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Procura um convite pelo token em claro, em qualquer empresa. O token é o
     * segredo enviado por email; quem o tem pode ver a que empresa se refere.
     */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('token_hash', static::hashToken($token))
            ->first();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
