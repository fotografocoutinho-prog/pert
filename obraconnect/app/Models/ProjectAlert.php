<?php

namespace App\Models;

use App\Alerts\AlertType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta automático de uma obra (ou da empresa, sem obra). Abre quando a condição aparece, fecha
 * sozinho quando desaparece; o email sai uma vez, quando abre (ou quando passa de aviso a grave).
 */
class ProjectAlert extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'data' => 'array',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** Endereço na aplicação (relativo a /app). */
    public function path(): string
    {
        if ($this->project_id === null) {
            return $this->type->companyPath() ?? '/app';
        }

        return "/app/obras/{$this->project_id}".($this->tab ? "?separador={$this->tab}" : '');
    }
}
