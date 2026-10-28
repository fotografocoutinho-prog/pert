<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Identidade global. O acesso a dados de empresa passa sempre por uma Membership.
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** Iguais aos valores por omissão da BD, para instâncias acabadas de criar. */
    protected $attributes = [
        'is_active' => true,
        'locale' => 'pt_PT',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'platform_role' => PlatformRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Associações em todas as empresas. O filtro por empresa é removido de propósito:
     * a relação já está limitada a este utilizador.
     */
    public function allMemberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(TenantScope::class);
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    public function activeMembershipFor(int $tenantId): ?Membership
    {
        return $this->allMemberships()
            ->where('tenant_id', $tenantId)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    public function hasPlatformRole(PlatformRole ...$roles): bool
    {
        return $this->platform_role !== null && in_array($this->platform_role, $roles, true);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
