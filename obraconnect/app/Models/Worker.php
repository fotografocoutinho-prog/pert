<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trabalhador (da empresa ou de um subempreiteiro). O NIF fica cifrado na base de dados.
 */
#[Fillable(['name', 'nif', 'category', 'hourly_cost_cents', 'partner_id', 'user_id', 'active'])]
class Worker extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'active' => true,
        'hourly_cost_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'nif' => 'encrypted',
            'hourly_cost_cents' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
