<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Departamentos da empresa e quem está em cada um (uma pessoa num departamento, ou em nenhum).
 * Consultar: quem vê a empresa. Alterar: departments.manage.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        abort_unless(Gate::any(['company.view', 'members.view', 'departments.manage']), 403);

        $departments = Department::query()
            ->with(['memberships' => fn ($q) => $q->select(['id', 'department_id'])])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $departments->map(fn (Department $d) => $this->present($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('departments.manage');

        $department = Department::query()->create($this->validated($request));
        $this->auditor->record('department.created', $department, ['name' => $department->name]);

        return response()->json(['data' => $this->present($department->load('memberships'))], 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorize('departments.manage');

        $department->update($this->validated($request, $department));
        $changes = $this->auditor->changes($department);
        if ($changes['new'] !== []) {
            $this->auditor->record('department.updated', $department, $changes);
        }

        return response()->json(['data' => $this->present($department->load('memberships'))]);
    }

    /** Apagar tira primeiro as pessoas do departamento (ficam sem departamento). */
    public function destroy(Department $department): Response
    {
        $this->authorize('departments.manage');

        $people = DB::transaction(function () use ($department): int {
            $people = Membership::query()->where('department_id', $department->id)->update(['department_id' => null]);
            $department->delete();

            return $people;
        });

        $this->auditor->record('department.deleted', null, ['id' => $department->id, 'name' => $department->name, 'people' => $people]);

        return response()->noContent();
    }

    /** Quem fica no departamento: as pessoas indicadas (saem do departamento onde estavam) e só elas. */
    public function syncMembers(Request $request, Department $department): JsonResponse
    {
        $this->authorize('departments.manage');

        $ids = $request->validate([
            'membership_ids' => ['present', 'array', 'max:1000'],
            'membership_ids.*' => ['integer', Rule::exists('memberships', 'id')->where('tenant_id', $this->tenantContext()->id())],
        ], [], ['membership_ids.*' => 'pessoa'])['membership_ids'];
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $before = Membership::query()->where('department_id', $department->id)->pluck('id')->all();

        DB::transaction(function () use ($department, $ids): void {
            Membership::query()->where('department_id', $department->id)->whereNotIn('id', $ids)->update(['department_id' => null]);
            Membership::query()->whereIn('id', $ids)->update(['department_id' => $department->id]);
        });

        $added = array_values(array_diff($ids, $before));
        $removed = array_values(array_diff($before, $ids));
        if ($added !== [] || $removed !== []) {
            $this->auditor->record('department.members_changed', $department, ['added' => $added, 'removed' => $removed]);
        }

        return response()->json(['data' => $this->present($department->load('memberships'))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Department $department = null): array
    {
        $required = $department ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:100',
                Rule::unique('departments', 'name')->where('tenant_id', $this->tenantContext()->id())->ignore($department?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['name' => 'nome', 'description' => 'descrição']);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'description' => $department->description,
            'membership_ids' => $department->memberships->pluck('id')->values(),
            'members_count' => $department->memberships->count(),
        ];
    }
}
