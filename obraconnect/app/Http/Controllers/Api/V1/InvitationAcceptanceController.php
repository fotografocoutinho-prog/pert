<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenancy\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationAcceptanceController extends Controller
{
    /**
     * Página pública do convite: mostra só o necessário para o utilizador decidir.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = strlen($token) === 64 ? Invitation::findByToken($token) : null;

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json(['message' => 'Convite inválido, expirado ou já utilizado.', 'code' => 'INVITATION_INVALID'], 404);
        }

        $tenant = $invitation->tenant()->first();
        $role = $invitation->role()->withoutGlobalScope(TenantScope::class)->first();

        return response()->json(['data' => [
            'company' => $tenant?->name,
            'role' => $role?->name,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'account_exists' => User::query()->where('email', $invitation->email)->exists(),
        ]]);
    }

    public function store(Request $request, AcceptInvitation $accept): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        $membership = $accept->handle($request->user(), $data['token']);

        return response()->json(['data' => ['tenant_id' => $membership->tenant_id]]);
    }
}
