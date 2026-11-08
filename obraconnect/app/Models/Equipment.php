<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Equipamento da empresa, com etiqueta QR que abre a sua ficha.
 */
#[Fillable(['code', 'name', 'category', 'serial_number', 'hourly_cost_cents', 'status', 'project_id', 'notes'])]
class Equipment extends Model
{
    use BelongsToTenant;

    protected $table = 'equipment';

    protected $attributes = [
        'status' => 'available',
        'hourly_cost_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'hourly_cost_cents' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(EquipmentUsage::class)->orderByDesc('used_on');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class)->orderByDesc('performed_on');
    }

    protected static function booted(): void
    {
        static::creating(function (self $equipment): void {
            $equipment->qr_token ??= Str::random(32);
        });
    }
}
