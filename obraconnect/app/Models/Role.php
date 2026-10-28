<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de acesso de uma empresa. Os perfis-modelo vivem em App\Access\RoleTemplates
 * e são copiados para cada empresa nova.
 */
#[Fillable(['key', 'name', 'description', 'template_key'])]
class Role extends Model
{
    use BelongsToTenant;

    public const ADMIN = 'admin';

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function isAdmin(): bool
    {
        return $this->key === self::ADMIN;
    }
}
