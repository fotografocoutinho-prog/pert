<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportGrantResource;
use App\Models\SupportAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Autorizações dadas pela empresa ao suporte técnico da plataforma.
 */
class SupportGrantController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('support.manage');

        return SupportGrantResource::collection(
            SupportAccessGrant::query()->with(['grantedBy', 'lastUsedBy'])->latest('id')->limit(50)->get()
        );
    }

    public function store(Request $request): SupportGrantResource
    {
        $this->authorize('support.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'hours' => ['required', 'integer', 'min:1', 'max:'.config('obraconnect.support.max_grant_hours')],
        ], [], ['reason' => 'motivo', 'hours' => 'duração']);

        $grant = SupportAccessGrant::query()->create([
            'granted_by_user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'access_level' => 'read',
            'expires_at' => now()->addHours($data['hours']),
        ]);

        $this->auditor->record('support.granted', $grant, ['hours' => $data['hours'], 'reason' => $data['reason']]);

        return new SupportGrantResource($grant->load('grantedBy'));
    }

    public function destroy(Request $request, SupportAccessGrant $supportAccessGrant): Response
    {
        $this->authorize('support.manage');

        if ($supportAccessGrant->revoked_at === null) {
            $supportAccessGrant->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id])->save();
            $this->auditor->record('support.revoked', $supportAccessGrant);
        }

        return response()->noContent();
    }
}
