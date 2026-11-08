<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Enums\VatMode;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orçamento de uma obra. Aprovado é a base do contrato e do custo previsto; muda-se por revisão.
 */
#[Fillable(['code', 'name', 'vat_mode', 'vat_rate', 'overhead_pct', 'profit_pct', 'notes'])]
class Budget extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'vat_mode' => 'normal',
        'vat_rate' => 23,
        'overhead_pct' => 0,
        'profit_pct' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'vat_mode' => VatMode::class,
            'version' => 'integer',
            'vat_rate' => 'decimal:2',
            'overhead_pct' => 'decimal:2',
            'profit_pct' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(BudgetChapter::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'revision_of_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isEditable(): bool
    {
        return $this->status === BudgetStatus::Draft;
    }

    /** Fator do preço de venda sobre o custo: (1 + encargos) × (1 + margem). */
    public function markup(): float
    {
        return (1 + (float) $this->overhead_pct / 100) * (1 + (float) $this->profit_pct / 100);
    }

    /** Taxa de IVA aplicada (0 em autoliquidação e isenção). */
    public function effectiveVatRate(): float
    {
        return $this->vat_mode === VatMode::Normal ? (float) $this->vat_rate : 0.0;
    }
}
