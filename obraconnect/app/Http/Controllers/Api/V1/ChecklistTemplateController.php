<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Inspections\ChecklistItems;
use App\Inspections\InspectionWorkflow;
use App\Models\ChecklistTemplate;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Modelos de listas de verificação da empresa. Consultar: quem gere os modelos ou trabalha com
 * inspeções (para escolher um ao criar a inspeção). Alterar: checklists.manage.
 */
class ChecklistTemplateController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => ChecklistTemplate::query()
            ->with('specialty:id,code,name')
            ->withCount(['items', 'inspections'])
            ->orderBy('name')
            ->get()
            ->map(fn (ChecklistTemplate $t) => $this->present($t))]);
    }

    public function show(ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $this->present($this->fresh($checklistTemplate), withItems: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('checklists.manage');

        $data = $this->validated($request);
        $items = ChecklistItems::normalize($data['items']);

        $template = DB::transaction(function () use ($data, $items, $request): ChecklistTemplate {
            $template = new ChecklistTemplate(array_intersect_key($data, array_flip(['name', 'description', 'specialty_id'])));
            $template->created_by_user_id = $request->user()->id;
            $template->save();
            $template->items()->createMany($items);

            return $template;
        });

        $this->auditor->record('checklist.created', $template, ['name' => $template->name, 'items' => count($items)]);

        return response()->json(['data' => $this->present($this->fresh($template), withItems: true)], 201);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorize('checklists.manage');

        $data = $this->validated($request, $checklistTemplate);
        $template = $checklistTemplate;

        DB::transaction(function () use ($data, $template): void {
            $template->fill(array_intersect_key($data, array_flip(['name', 'description', 'specialty_id'])));
            if (array_key_exists('archived', $data) && (bool) $data['archived'] !== $template->isArchived()) {
                $template->archived_at = $data['archived'] ? now() : null;
            }

            // Pontos diferentes = versão nova. As inspeções já criadas guardaram a sua cópia.
            if (array_key_exists('items', $data)) {
                $items = ChecklistItems::normalize($data['items']);
                if (ChecklistItems::comparable($items) !== ChecklistItems::comparable($template->items)) {
                    $template->items()->delete();
                    $template->items()->createMany($items);
                    $template->version++;
                }
            }
            $template->save();
        });

        $changes = $this->auditor->changes($template);
        if (array_key_exists('archived_at', $changes['new'])) {
            $this->auditor->record($template->isArchived() ? 'checklist.archived' : 'checklist.restored', $template, ['name' => $template->name]);
            unset($changes['new']['archived_at'], $changes['old']['archived_at']);
        }
        if ($changes['new'] !== []) {
            $this->auditor->record('checklist.updated', $template, $changes);
        }

        return response()->json(['data' => $this->present($this->fresh($template), withItems: true)]);
    }

    public function destroy(ChecklistTemplate $checklistTemplate): Response
    {
        $this->authorize('checklists.manage');

        if ($checklistTemplate->inspections()->exists()) {
            throw ApiException::conflict('Há inspeções criadas a partir deste modelo. Arquive-o em vez de o apagar.', 'CHECKLIST_IN_USE');
        }

        $checklistTemplate->delete();
        $this->auditor->record('checklist.deleted', null, ['id' => $checklistTemplate->id, 'name' => $checklistTemplate->name]);

        return response()->noContent();
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::any(['checklists.manage', ...InspectionWorkflow::VIEW_PERMISSIONS]), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ChecklistTemplate $template = null): array
    {
        $required = $template ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'specialty_id' => Specialty::rules($template?->specialty_id),
            'archived' => $template ? ['sometimes', 'boolean'] : ['prohibited'],
        ] + ChecklistItems::rules('items', required: $template === null), [], [
            'name' => 'nome',
            'description' => 'descrição',
            'specialty_id' => 'especialidade',
            'items' => 'pontos a verificar',
        ] + ChecklistItems::attributes());

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $taken = ChecklistTemplate::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
                ->when($template, fn ($q) => $q->whereKeyNot($template->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['name' => 'Já existe uma lista de verificação com este nome.']);
            }
        }

        return $data;
    }

    private function fresh(ChecklistTemplate $template): ChecklistTemplate
    {
        return $template->load(['specialty:id,code,name', 'items'])->loadCount(['items', 'inspections']);
    }

    /** @return array<string, mixed> */
    private function present(ChecklistTemplate $template, bool $withItems = false): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'specialty_id' => $template->specialty_id,
            'specialty' => $template->specialty ? ['id' => $template->specialty->id, 'code' => $template->specialty->code, 'name' => $template->specialty->name] : null,
            'version' => $template->version,
            'archived' => $template->isArchived(),
            'items_count' => (int) $template->items_count,
            'inspections_count' => (int) $template->inspections_count,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ] + ($withItems ? ['items' => $template->items->map(fn ($item) => ChecklistItems::present($item))->all()] : []);
    }
}
