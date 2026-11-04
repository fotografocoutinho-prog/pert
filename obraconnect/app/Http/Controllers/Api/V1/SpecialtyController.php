<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Especialidades da empresa. Consultar: quem vê a empresa ou alguma obra (para as escolher em
 * elementos e documentos). Alterar: specialties.manage.
 */
class SpecialtyController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        abort_unless(Gate::any(['company.view', 'projects.view', 'specialties.manage']), 403);

        return response()->json(['data' => Specialty::query()
            ->withCount(['elements', 'documents'])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (Specialty $s) => $this->present($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('specialties.manage');

        $data = $this->validated($request);
        $specialty = Specialty::query()->create($data + ['sort_order' => (int) Specialty::query()->max('sort_order') + 1]);
        $this->auditor->record('specialty.created', $specialty, ['code' => $specialty->code, 'name' => $specialty->name]);

        return response()->json(['data' => $this->present($specialty->loadCount(['elements', 'documents']))], 201);
    }

    public function update(Request $request, Specialty $specialty): JsonResponse
    {
        $this->authorize('specialties.manage');

        $data = $this->validated($request, $specialty);
        $archive = array_key_exists('archived', $data) ? (bool) $data['archived'] : null;
        unset($data['archived']);

        $specialty->fill($data);
        if ($archive !== null && $archive !== $specialty->isArchived()) {
            $specialty->archived_at = $archive ? now() : null;
        }
        $specialty->save();

        $changes = $this->auditor->changes($specialty);
        if (array_key_exists('archived_at', $changes['new'])) {
            $this->auditor->record($specialty->isArchived() ? 'specialty.archived' : 'specialty.restored', $specialty, ['code' => $specialty->code]);
            unset($changes['new']['archived_at'], $changes['old']['archived_at']);
        }
        if ($changes['new'] !== []) {
            $this->auditor->record('specialty.updated', $specialty, $changes);
        }

        return response()->json(['data' => $this->present($specialty->loadCount(['elements', 'documents']))]);
    }

    public function destroy(Specialty $specialty): Response
    {
        $this->authorize('specialties.manage');

        if ($specialty->isReferenced()) {
            throw ApiException::conflict('Há elementos, documentos, listas de verificação ou inspeções com esta especialidade. Arquive-a em vez de a apagar.', 'SPECIALTY_IN_USE');
        }

        $specialty->delete();
        $this->auditor->record('specialty.deleted', null, ['id' => $specialty->id, 'code' => $specialty->code, 'name' => $specialty->name]);

        return response()->noContent();
    }

    /** Junta as da lista habitual que faltam (pelo código). */
    public function addDefaults(): JsonResponse
    {
        $this->authorize('specialties.manage');

        $added = DB::transaction(fn () => Specialty::addDefaults());
        if ($added > 0) {
            $this->auditor->record('specialty.defaults_added', null, ['added' => $added]);
        }

        return response()->json(['data' => ['added' => $added]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Specialty $specialty = null): array
    {
        $required = $specialty ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => [$required, 'string', 'max:10', 'regex:/^\s*[A-Za-z0-9][A-Za-z0-9\-]*\s*$/'],
            'name' => [$required, 'string', 'max:100'],
            'archived' => $specialty ? ['sometimes', 'boolean'] : ['prohibited'],
        ], ['code.regex' => 'O código só pode ter letras, algarismos e hífenes (ex.: AVAC).'], ['code' => 'código', 'name' => 'nome']);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']));
            $taken = Specialty::query()->whereRaw('upper(code) = ?', [$data['code']])
                ->when($specialty, fn ($q) => $q->whereKeyNot($specialty->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => 'Já existe uma especialidade com este código.']);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Specialty $specialty): array
    {
        return [
            'id' => $specialty->id,
            'code' => $specialty->code,
            'name' => $specialty->name,
            'archived' => $specialty->isArchived(),
            'elements_count' => $specialty->elements_count ?? 0,
            'documents_count' => $specialty->documents_count ?? 0,
        ];
    }
}
