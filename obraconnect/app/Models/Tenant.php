<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Tenancy\TenantScope;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa cliente (tenant).
 *
 * As relações para modelos de empresa removem o TenantScope porque a própria
 * relação já filtra por tenant_id = este registo.
 */
#[Fillable([
    'name', 'legal_name', 'nif', 'email', 'phone', 'website',
    'address_line1', 'address_line2', 'postal_code', 'city', 'country_code',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'country_code' => 'PT',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'status_changed_at' => 'datetime',
            'settings' => 'array',
            'storage_used_bytes' => 'integer',
            'deletion_requested_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(TenantScope::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', MembershipStatus::Active->value);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class)->withoutGlobalScope(TenantScope::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class)->withoutGlobalScope(TenantScope::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function requiresMfa(): bool
    {
        return (bool) $this->setting('require_mfa', false);
    }

    public function changeStatus(TenantStatus $status, ?string $reason = null): void
    {
        $this->status = $status;
        $this->status_reason = $reason;
        $this->status_changed_at = now();
        $this->save();
    }
}
