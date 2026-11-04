<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departamento da empresa (ex.: Produção, Orçamentação, Compras). Cada pessoa está num ou em nenhum. */
#[Fillable(['name', 'description'])]
class Department extends Model
{
    use BelongsToTenant;

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
