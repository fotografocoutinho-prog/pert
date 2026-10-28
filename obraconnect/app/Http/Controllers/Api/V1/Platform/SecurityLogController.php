<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Eventos de segurança e de plataforma. Os eventos operacionais das empresas
 * (ex.: alterações de perfis) só são visíveis para a própria empresa.
 */
class SecurityLogController extends Controller
{
    private const PREFIXES = ['auth.', 'platform.', 'support.'];

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->where(function ($q) {
                foreach (self::PREFIXES as $prefix) {
                    $q->orWhere('event', 'like', $prefix.'%');
                }
            })
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', 'like', $event.'%'))
            ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->with('actor:id,name,email')
            ->latest('id')
            ->paginate(50);

        return AuditLogResource::collection($logs);
    }
}
