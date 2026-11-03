<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cliente da empresa (quem encomenda a obra). Com obras não se apaga: arquiva-se. */
#[Fillable([
    'type', 'name', 'nif', 'country_code', 'email', 'phone', 'contact_name',
    'address', 'postal_code', 'city', 'notes',
])]
class Client extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'country_code' => 'PT',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'archived_at' => 'datetime',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
