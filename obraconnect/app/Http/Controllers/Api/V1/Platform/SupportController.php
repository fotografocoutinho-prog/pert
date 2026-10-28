<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Http\Resources\SupportGrantResource;
use App\Models\SupportAccessGrant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * O suporte só entra numa empresa através de uma autorização ativa dada por ela.
 */
class SupportController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): AnonymousResourceCollection
    {
        $grants = SupportAccessGrant::withoutGlobalScope(TenantScope::class)
            ->active()
            ->with(['tenant:id,name', 'grantedBy:id,name', 'lastUsedBy:id,name'])
            ->latest('id')
            ->get();

        return SupportGrantResource::collection($grants);
    }

    public function enter(Request $request, int $grantId): JsonResponse
    {
        if (! $request->hasSession()) {
            throw ApiException::unprocessable('O acesso de suporte exige sessão no browser.', 'SESSION_REQUIRED');
        }

        $grant = SupportAccessGrant::withoutGlobalScope(TenantScope::class)->with('tenant')->find($grantId);

        if ($grant === null || ! $grant->isActive()) {
            throw ApiException::forbidden('Autorização inexistente, expirada ou revogada.', 'SUPPORT_GRANT_INVALID');
        }

        $request->session()->put(ResolveTenant::SUPPORT_SESSION_KEY, $grant->id);

        $this->auditor->record('support.session_started', $grant, ['reason' => $grant->reason], tenantId: $grant->tenant_id, actorType: 'support');

        return response()->json(['data' => [
            'grant_id' => $grant->id,
            'tenant' => ['id' => $grant->tenant_id, 'name' => $grant->tenant?->name],
            'expires_at' => $grant->expires_at->toIso8601String(),
        ]]);
    }

    public function leave(Request $request): JsonResponse
    {
        $grantId = $request->hasSession() ? $request->session()->pull(ResolveTenant::SUPPORT_SESSION_KEY) : null;

        if ($grantId) {
            $grant = SupportAccessGrant::withoutGlobalScope(TenantScope::class)->find($grantId);
            if ($grant) {
                $this->auditor->record('support.session_ended', $grant, [], tenantId: $grant->tenant_id, actorType: 'support');
            }
        }

        return response()->json(['data' => ['left' => (bool) $grantId]]);
    }
}
