<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $this->authorize('audit.view');

        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
            'actor_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->forTenant($this->tenantContext()->id())
            ->with('actor:id,name,email')
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', 'like', $event.'%'))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(50);

        return AuditLogResource::collection($logs);
    }
}
