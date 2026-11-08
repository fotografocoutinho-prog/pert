<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Auto de medição: ao dono de obra (sobre o orçamento) ou de um subempreiteiro (sobre o contrato).
 */
#[Fillable(['period_start', 'period_end', 'notes'])]
class Certificate extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
        'amount_cents' => 0,
        'retention_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'number' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_cents' => 'integer',
            'retention_cents' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CertificateLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isOwner(): bool
    {
        return $this->contract_id === null;
    }
}
