<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerPortalUser;
use App\Models\Tenant;
use App\Models\User;
use App\Portal\PortalAccess;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Convite para o portal: página pública (só o necessário para decidir) e aceitação com sessão iniciada. */
class PortalInvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $access = strlen($token) === 64 ? PartnerPortalUser::findByToken($token) : null;
        if ($access === null || ! $access->isPending()) {
            return response()->json(['message' => 'Convite inválido, expirado ou já utilizado.', 'code' => 'PORTAL_INVITATION_INVALID'], 404);
        }

        return response()->json(['data' => [
            'company' => Tenant::query()->whereKey($access->tenant_id)->value('name'),
            'partner' => Partner::query()->withoutGlobalScope(TenantScope::class)->whereKey($access->partner_id)->value('name'),
            'name' => $access->name,
            'email' => $access->email,
            'expires_at' => $access->expires_at?->toIso8601String(),
            'account_exists' => User::query()->where('email', $access->email)->exists(),
        ]]);
    }

    public function accept(Request $request, PortalAccess $portal): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $access = $portal->accept($request->user(), $data['token']);

        return response()->json(['data' => ['access_id' => $access->id]]);
    }
}
