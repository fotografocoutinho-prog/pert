<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento com validade de um fornecedor ou subempreiteiro (alvará, seguro, não dívida...).
 */
#[Fillable(['title', 'reference', 'expires_on'])]
class PartnerDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'partner_documents';

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
