<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Linha do histórico de uma inspeção. Só se cria por App\Inspections\InspectionHistory e nunca
 * se altera nem apaga (sai apenas com a própria inspeção, quando um rascunho é apagado).
 */
class InspectionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico das inspeções não se altera.'));
        static::deleting(fn () => throw new LogicException('O histórico das inspeções não se apaga.'));
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return array<string, mixed>|null */
    public function payloadData(): ?array
    {
        return $this->payload === null ? null : json_decode($this->payload, true);
    }
}
