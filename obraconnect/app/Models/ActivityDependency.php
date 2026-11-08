<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precedência entre atividades (FS, SS, FF, SF) com folga em dias úteis.
 */
#[Fillable(['predecessor_id', 'successor_id', 'type', 'lag_days'])]
class ActivityDependency extends Model
{
    use BelongsToTenant;

    protected $table = 'activity_dependencies';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'lag_days' => 'integer',
        ];
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'predecessor_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'successor_id');
    }
}
