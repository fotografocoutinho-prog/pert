<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pessoa na equipa de uma obra, com o perfil que tem nessa obra.
 */
#[Fillable(['membership_id', 'role_id', 'added_by_user_id'])]
class ProjectMember extends Model
{
    use BelongsToTenant;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
