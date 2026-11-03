<?php

namespace App\Models;

use App\Enums\DiaryEntryStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registo diário de uma obra. Em rascunho pode ser alterado; depois de submetido fica fechado.
 */
#[Fillable(['entry_date', 'weather', 'work_summary', 'notes'])]
class DiaryEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'site_diary_entries';

    /** Estado do tempo: chave guardada => rótulo. */
    public const WEATHER = [
        'limpo' => 'Céu limpo',
        'nublado' => 'Nublado',
        'chuva' => 'Chuva',
        'chuva_forte' => 'Chuva forte',
        'vento' => 'Vento forte',
        'nevoeiro' => 'Nevoeiro',
        'geada' => 'Geada',
        'neve' => 'Neve',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiaryEntryStatus::class,
            'entry_date' => 'date',
            'weather' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DiaryItem::class, 'entry_id')->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === DiaryEntryStatus::Draft;
    }
}
