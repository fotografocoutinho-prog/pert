<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PartnerKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerPortalUser;
use App\Portal\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acesso de um subempreiteiro ao portal, do lado da empresa (subcontractors.manage). */
class PartnerPortalController extends Controller
{
    public function __construct(private readonly PortalAccess $portal) {}

    public function index(Partner $partner): JsonResponse
    {
        $this->guard($partner);

        return response()->json(['data' => $this->list($partner)]);
    }

    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->guard($partner);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['name' => 'nome']);
        $this->portal->invite($partner, $data['email'], $data['name'], $request->user());

        return response()->json(['data' => $this->list($partner)], 201);
    }

    public function resend(Request $request, Partner $partner, PartnerPortalUser $portalUser): JsonResponse
    {
        $this->guard($partner, $portalUser);
        $this->portal->resend($portalUser, $request->user());

        return response()->json(['data' => $this->list($partner)]);
    }

    public function destroy(Partner $partner, PartnerPortalUser $portalUser): JsonResponse
    {
        $this->guard($partner, $portalUser);
        $this->portal->revoke($portalUser);

        return response()->json(['data' => $this->list($partner)]);
    }

    private function guard(Partner $partner, ?PartnerPortalUser $portalUser = null): void
    {
        $this->authorize('subcontractors.manage');
        if ($partner->kind !== PartnerKind::Subcontractor) {
            throw ApiException::unprocessable('O portal é só para subempreiteiros.', 'PORTAL_NOT_SUBCONTRACTOR');
        }
        abort_if($portalUser !== null && $portalUser->partner_id !== $partner->id, 404);
    }

    private function list(Partner $partner): array
    {
        $labels = ['active' => 'Com acesso', 'pending' => 'Convite enviado', 'expired' => 'Convite expirado', 'revoked' => 'Sem acesso (revogado)'];

        return PartnerPortalUser::query()->where('partner_id', $partner->id)->orderByRaw('revoked_at is not null')->orderBy('name')->get()
            ->map(fn (PartnerPortalUser $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status(),
                'status_label' => $labels[$u->status()],
                'invited_at' => $u->created_at?->toIso8601String(),
                'accepted_at' => $u->accepted_at?->toIso8601String(),
                'expires_at' => $u->expires_at?->toIso8601String(),
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
            ])->values()->all();
    }
}
