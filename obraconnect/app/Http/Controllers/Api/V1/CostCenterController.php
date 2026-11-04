<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Centros de custo em árvore. Código vazio = automático (1, 2… na raiz; 1.01, 1.02… dentro de "1").
 * Com filhos ou obras não se apagam: arquivam-se. Consultar: quem vê a empresa ou edita obras.
 */
class CostCenterController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Lista plana por código: a interface monta a árvore. */
    public function index(): JsonResponse
    {
        abort_unless(Gate::any(['company.view', 'cost_centers.manage', 'projects.create', 'projects.update']), 403);

        return response()->json(['data' => CostCenter::query()
            ->withCount(['projects', 'children'])
            ->orderBy('code')
            ->get()
            ->map(fn (CostCenter $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('cost_centers.manage');

        $data = $this->validated($request);
        $parent = isset($data['parent_id']) ? CostCenter::query()->findOrFail($data['parent_id']) : null;

        $center = new CostCenter($data);
        $center->code = filled($data['code'] ?? null) ? $data['code'] : $this->nextCode($parent);
        $center->created_by_user_id = $request->user()->id;
        $center->save();

        $this->auditor->record('cost_center.created', $center, ['code' => $center->code, 'name' => $center->name]);

        return response()->json(['data' => $this->present($center->loadCount(['projects', 'children']))], 201);
    }

    public function update(Request $request, CostCenter $costCenter): JsonResponse
    {
        $this->authorize('cost_centers.manage');

        $data = $this->validated($request, $costCenter);
        $archive = array_key_exists('archived', $data) ? (bool) $data['archived'] : null;
        unset($data['archived']);
        if (array_key_exists('code', $data) && blank($data['code'])) {
            unset($data['code']);
        }

        $costCenter->fill($data);
        if ($archive !== null && $archive !== $costCenter->isArchived()) {
            $costCenter->archived_at = $archive ? now() : null;
        }
        $costCenter->save();

        $changes = $this->auditor->changes($costCenter);
        if (array_key_exists('archived_at', $changes['new'])) {
            $this->auditor->record($costCenter->isArchived() ? 'cost_center.archived' : 'cost_center.restored', $costCenter, ['code' => $costCenter->code]);
            unset($changes['new']['archived_at'], $changes['old']['archived_at']);
        }
        if ($changes['new'] !== []) {
            $this->auditor->record('cost_center.updated', $costCenter, $changes);
        }

        return response()->json(['data' => $this->present($costCenter->loadCount(['projects', 'children']))]);
    }

    public function destroy(CostCenter $costCenter): Response
    {
        $this->authorize('cost_centers.manage');

        if ($costCenter->children()->exists() || $costCenter->projects()->exists()) {
            throw ApiException::conflict('Este centro de custo tem centros dentro dele ou obras. Arquive-o em vez de o apagar.', 'COST_CENTER_IN_USE');
        }

        $costCenter->delete();
        $this->auditor->record('cost_center.deleted', null, ['id' => $costCenter->id, 'code' => $costCenter->code, 'name' => $costCenter->name]);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CostCenter $center = null): array
    {
        $tenantId = $this->tenantContext()->id();
        $required = $center ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-\/]*$/',
                Rule::unique('cost_centers', 'code')->where('tenant_id', $tenantId)->ignore($center?->id)],
            'name' => [$required, 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_centers', 'id')->where('tenant_id', $tenantId)],
            'archived' => $center ? ['sometimes', 'boolean'] : ['prohibited'],
        ], [
            'code.regex' => 'O código só pode ter letras, algarismos, pontos, hífenes e barras.',
        ], ['code' => 'código', 'name' => 'nome', 'parent_id' => 'dentro de']);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (filled($data['code'] ?? null)) {
            $data['code'] = trim($data['code']);
            // Igual em SQLite e MySQL: "ARM" e "arm" são o mesmo código.
            $taken = CostCenter::query()->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])
                ->when($center, fn ($q) => $q->whereKeyNot($center->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => 'Já existe um centro de custo com este código.']);
            }
        }

        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null && (int) $parentId !== $center?->parent_id) {
            $parent = CostCenter::query()->findOrFail($parentId);
            if ($parent->isArchived()) {
                throw ValidationException::withMessages(['parent_id' => 'Esse centro de custo está arquivado.']);
            }
            // Não pode ficar dentro de si próprio nem de um descendente.
            for ($node = $parent; $node !== null; $node = $node->parent) {
                if ($center && $node->id === $center->id) {
                    throw ValidationException::withMessages(['parent_id' => 'Um centro de custo não pode ficar dentro de si próprio.']);
                }
            }
        }

        return $data;
    }

    private function nextCode(?CostCenter $parent): string
    {
        $siblings = CostCenter::query()->where('parent_id', $parent?->id)->pluck('code');
        $taken = CostCenter::query()->pluck('code')->map(fn ($c) => mb_strtolower($c))->flip();

        for ($n = $siblings->count() + 1; ; $n++) {
            $code = $parent ? $parent->code.'.'.str_pad((string) $n, 2, '0', STR_PAD_LEFT) : (string) $n;
            if (! $taken->has(mb_strtolower($code))) {
                return $code;
            }
        }
    }

    /** @return array<string, mixed> */
    private function present(CostCenter $center): array
    {
        return [
            'id' => $center->id,
            'parent_id' => $center->parent_id,
            'code' => $center->code,
            'name' => $center->name,
            'archived' => $center->isArchived(),
            'projects_count' => $center->projects_count ?? 0,
            'children_count' => $center->children_count ?? 0,
        ];
    }
}
