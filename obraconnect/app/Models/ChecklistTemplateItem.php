<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ponto de um modelo de lista de verificação, com as evidências que exige. */
#[Fillable(['sort_order', 'text', 'guidance', 'requires_photo', 'min_photos', 'requires_measure', 'measure_unit', 'measure_min', 'measure_max'])]
class ChecklistTemplateItem extends Model
{
    use BelongsToTenant;

    /** Campos copiados para cada inspeção criada a partir do modelo. */
    public const COPIED = ['text', 'guidance', 'requires_photo', 'min_photos', 'requires_measure', 'measure_unit', 'measure_min', 'measure_max'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'requires_photo' => 'boolean',
            'min_photos' => 'integer',
            'requires_measure' => 'boolean',
            'measure_min' => 'decimal:4',
            'measure_max' => 'decimal:4',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }
}
