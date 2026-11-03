<?php

namespace App\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Mantém tenants.storage_used_bytes (quota storage_mb do plano). Atualizações
 * atómicas: vários envios em simultâneo não perdem contagens.
 */
class StorageUsage
{
    /** Positivo soma, negativo subtrai (nunca abaixo de zero). */
    public function add(Tenant $tenant, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        $query = DB::table('tenants')->where('id', $tenant->id);

        $bytes > 0
            ? $query->increment('storage_used_bytes', $bytes)
            : $query->update(['storage_used_bytes' => DB::raw(sprintf(
                'case when storage_used_bytes > %1$d then storage_used_bytes - %1$d else 0 end',
                -$bytes,
            ))]);

        $tenant->refresh();
    }
}
