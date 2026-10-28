<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenancy\InviteMember;
use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('members.view');

        $invitations = Invitation::query()
            ->with(['role', 'invitedBy'])
            ->when($request->query('status', 'pending') === 'pending', fn ($q) => $q->pending())
            ->latest('id')
            ->paginate(50);

        return InvitationResource::collection($invitations);
    }

    public function store(Request $request, InviteMember $invite): InvitationResource
    {
        $this->authorize('members.invite');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->id())],
        ]);

        $invitation = $invite->handle($this->membership(), $data['email'], Role::query()->findOrFail($data['role_id']));

        return new InvitationResource($invitation->load(['role', 'invitedBy']));
    }

    public function resend(Invitation $invitation, InviteMember $invite): InvitationResource
    {
        $this->authorize('members.invite');

        return new InvitationResource($invite->resend($invitation, $this->membership())->load(['role', 'invitedBy']));
    }

    public function destroy(Invitation $invitation, Auditor $auditor): Response
    {
        $this->authorize('members.invite');

        if ($invitation->accepted_at !== null) {
            throw ApiException::unprocessable('Este convite já foi aceite.', 'INVITATION_ACCEPTED');
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $auditor->record('invitation.revoked', $invitation, ['email' => $invitation->email]);

        return response()->noContent();
    }
}
