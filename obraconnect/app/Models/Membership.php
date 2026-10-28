<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Associação de um utilizador a uma empresa, com um perfil.
 */
#[Fillable(['user_id', 'role_id', 'status', 'job_title', 'invited_by_user_id', 'joined_at'])]
class Membership extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active->value);
    }
}
