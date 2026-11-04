<?php

namespace App\Models;

use App\Enums\InspectionResult;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ponto verificado numa inspeção: o que se pede (copiado do modelo) e o que se encontrou. */
#[Fillable([
    'sort_order', 'text', 'guidance', 'requires_photo', 'min_photos', 'requires_measure', 'measure_unit', 'measure_min', 'measure_max',
    'result', 'measure_value', 'notes',
])]
class InspectionItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'requires_photo' => 'boolean',
            'min_photos' => 'integer',
            'requires_measure' => 'boolean',
            'measure_min' => 'decimal:4',
            'measure_max' => 'decimal:4',
            'measure_value' => 'decimal:4',
            'result' => InspectionResult::class,
            'answered_at' => 'datetime',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order')->orderBy('id');
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    /** Fotografias que este ponto exige (0 = nenhuma). */
    public function photosRequired(): int
    {
        return $this->requires_photo ? max(1, $this->min_photos) : 0;
    }

    /** Valor medido fora dos limites indicados (sem valor ou sem limites: não está fora). */
    public function isOutOfTolerance(): bool
    {
        if ($this->measure_value === null) {
            return false;
        }
        $value = (float) $this->measure_value;

        return ($this->measure_min !== null && $value < (float) $this->measure_min)
            || ($this->measure_max !== null && $value > (float) $this->measure_max);
    }
}
