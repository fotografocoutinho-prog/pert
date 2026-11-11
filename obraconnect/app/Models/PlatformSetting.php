<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma definição da plataforma (sem empresa), guardada como JSON e editável no backoffice.
 *
 * @property string $key
 * @property array<string, mixed> $value
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function for(string $key): ?self
    {
        return static::query()->where('key', $key)->first();
    }
}
