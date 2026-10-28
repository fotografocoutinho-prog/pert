<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenancy\ManageMembership;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('members.view');

        $search = trim((string) $request->query('search'));

        $members = Membership::query()
            ->with(['user', 'role'])
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return MembershipResource::collection($members);
    }

    public function update(Request $request, Membership $membership, ManageMembership $manage): MembershipResource
    {
        $this->authorize('members.manage');

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->id())],
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return new MembershipResource($manage->update($this->membership(), $membership, $data));
    }

    public function destroy(Membership $membership, ManageMembership $manage): Response
    {
        $this->authorize('members.manage');

        $manage->remove($this->membership(), $membership);

        return response()->noContent();
    }
}
