<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de um auto: quantidade anterior, deste período e valor.
 */
#[Fillable(['budget_item_id', 'contract_item_id', 'code', 'description', 'unit', 'unit_price_cents', 'qty_contract', 'qty_previous', 'qty_period', 'amount_cents', 'sort_order'])]
class CertificateLine extends Model
{
    use BelongsToTenant;

    protected $table = 'certificate_lines';

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'qty_contract' => 'decimal:3',
            'qty_previous' => 'decimal:3',
            'qty_period' => 'decimal:3',
            'amount_cents' => 'integer',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}
