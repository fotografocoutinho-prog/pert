<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custo lançado à mão numa obra (comprometido ou realizado).
 */
#[Fillable(['cost_center_id', 'category', 'kind', 'description', 'amount_cents', 'incurred_on'])]
class CostEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'cost_entries';

    protected function casts(): array
    {
        return [
            'category' => CostCategory::class,
            'amount_cents' => 'integer',
            'incurred_on' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
