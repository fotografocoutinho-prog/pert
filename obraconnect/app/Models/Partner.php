<?php

namespace App\Models;

use App\Enums\PartnerKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor ou subempreiteiro da empresa.
 */
#[Fillable(['kind', 'name', 'nif', 'email', 'phone', 'contact_name', 'address', 'notes'])]
class Partner extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => PartnerKind::class,
            'archived_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class)->orderBy('expires_on');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'partner_specialty')->withPivot('tenant_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
