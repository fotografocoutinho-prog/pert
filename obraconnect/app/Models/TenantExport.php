<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['requested_by_user_id', 'status', 'disk'])]
class TenantExport extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Completed
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
