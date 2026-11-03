<?php

namespace App\Models;

use App\Enums\DiaryItemKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kind', 'description', 'quantity', 'unit', 'company', 'sort_order'])]
class DiaryItem extends Model
{
    use BelongsToTenant;

    protected $table = 'site_diary_items';

    protected function casts(): array
    {
        return [
            'kind' => DiaryItemKind::class,
            'quantity' => 'decimal:3',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class, 'entry_id');
    }
}
