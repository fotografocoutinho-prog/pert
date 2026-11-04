<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\ElementType;
use App\Enums\LocationKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\MediaResource;
use App\Models\ConstructionElement;
use App\Models\DiaryItem;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Estrutura da obra: edifícios, pisos e zonas, e os elementos construtivos em cada um.
 * Quem vê a obra vê a estrutura; alterá-la exige structure.manage nessa obra.
 */
class StructureController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Tudo de uma vez (listas planas): a interface monta a árvore. */
    public function index(Project $project): JsonResponse
    {
        $this->authorize('projects.view', $project);

        return response()->json(['data' => [
            'locations' => $project->locations()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ProjectLocation $l) => $this->presentLocation($l)),
            'elements' => $project->elements()->orderBy('code')->get()
                ->map(fn (ConstructionElement $e) => $this->presentElement($e)),
            'element_types' => collect(ElementType::cases())
                ->map(fn (ElementType $t) => ['value' => $t->value, 'label' => $t->label(), 'prefix' => $t->prefix()]),
            // Para mostrar e escolher a especialidade de cada elemento (também sem rede).
            'specialties' => Specialty::query()->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (Specialty $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'archived' => $s->isArchived()]),
        ]]);
    }

    public function storeLocation(Request $request, Project $project): JsonResponse
    {
        $this->authorizeWrite($project);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(LocationKind::class)],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('project_locations', 'id')->where('project_id', $project->id)],
        ], [], ['kind' => 'tipo', 'name' => 'nome', 'parent_id' => 'dentro de']);

        $kind = LocationKind::from($data['kind']);
        $parent = isset($data['parent_id']) ? $project->locations()->findOrFail($data['parent_id']) : null;

        if (! in_array($parent?->kind, $kind->allowedParents(), true)) {
            $where = collect($kind->allowedParents())->map(fn (?LocationKind $k) => $k ? 'num '.mb_strtolower($k->label()) : 'na raiz da obra')->join(' ou ');
            throw ValidationException::withMessages(['parent_id' => "{$kind->label()} só pode ficar {$where}."]);
        }

        $location = $project->locations()->create([
            'kind' => $kind,
            'name' => trim($data['name']),
            'parent_id' => $parent?->id,
            'sort_order' => (int) $project->locations()->where('parent_id', $parent?->id)->max('sort_order') + 1,
        ]);

        $this->auditor->record('structure.location_created', $location, ['project' => $project->code, 'kind' => $kind->value, 'name' => $location->name]);

        return response()->json(['data' => $this->presentLocation($location)], 201);
    }

    public function updateLocation(Request $request, Project $project, ProjectLocation $location): JsonResponse
    {
        $this->authorizeWrite($project);

        $location->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], [], ['name' => 'nome']));

        $changes = $this->auditor->changes($location);
        if ($changes['new'] !== []) {
            $this->auditor->record('structure.location_updated', $location, $changes);
        }

        return response()->json(['data' => $this->presentLocation($location)]);
    }

    public function destroyLocation(Project $project, ProjectLocation $location): Response
    {
        $this->authorizeWrite($project);

        if ($location->children()->exists() || $location->elements()->exists()) {
            throw ApiException::conflict('Só se apaga vazio: retire primeiro o que tem dentro (pisos, zonas ou elementos).', 'LOCATION_NOT_EMPTY');
        }
        if ($location->isReferenced()) {
            throw ApiException::conflict('Há registos do diário, fotografias ou documentos ligados a este local. Mude-os primeiro.', 'LOCATION_IN_USE');
        }

        $location->delete();
        $this->auditor->record('structure.location_deleted', $project, ['kind' => $location->kind->value, 'name' => $location->name]);

        return response()->noContent();
    }

    public function storeElement(Request $request, Project $project): JsonResponse
    {
        $this->authorizeWrite($project);
        $data = $this->validateElement($request, $project);

        $type = ElementType::from($data['element_type']);
        $element = $project->elements()->make($data + ['code' => null]);
        $element->code = filled($data['code'] ?? null) ? trim($data['code']) : $this->nextCode($project, $type);
        $element->created_by_user_id = $request->user()->id;
        $element->save();

        $this->auditor->record('structure.element_created', $element, ['project' => $project->code, 'code' => $element->code]);

        return response()->json(['data' => $this->presentElement($element)], 201);
    }

    public function updateElement(Request $request, Project $project, ConstructionElement $element): JsonResponse
    {
        $this->authorizeWrite($project);

        $element->update($this->validateElement($request, $project, $element));

        // O local das ligações segue o elemento (o "onde" de um elemento é sempre o dele).
        if ($element->wasChanged('location_id')) {
            foreach ([$element->diaryItems(), $element->media(), $element->documents()] as $linked) {
                $linked->update(['location_id' => $element->location_id]);
            }
        }

        $changes = $this->auditor->changes($element);
        if ($changes['new'] !== []) {
            $this->auditor->record('structure.element_updated', $element, $changes);
        }

        return response()->json(['data' => $this->presentElement($element)]);
    }

    public function destroyElement(Project $project, ConstructionElement $element): Response
    {
        $this->authorizeWrite($project);

        if ($element->isReferenced()) {
            throw ApiException::conflict('Há registos do diário, fotografias ou documentos ligados a este elemento. Mude-os primeiro.', 'ELEMENT_IN_USE');
        }

        $element->delete();
        $this->auditor->record('structure.element_deleted', $project, ['code' => $element->code]);

        return response()->noContent();
    }

    /** Tudo o que está ligado a um elemento. */
    public function elementLinks(Project $project, ConstructionElement $element): JsonResponse
    {
        $this->authorize('projects.view', $project);

        return response()->json(['data' => $this->links($project, [], [$element->id])]);
    }

    /** Tudo o que está ligado a um local ou a qualquer coisa dentro dele (pisos, zonas, elementos). */
    public function locationLinks(Project $project, ProjectLocation $location): JsonResponse
    {
        $this->authorize('projects.view', $project);

        $all = $project->locations()->get(['id', 'parent_id']);
        $ids = [$location->id];
        for ($i = 0; $i < count($ids); $i++) {
            array_push($ids, ...$all->where('parent_id', $ids[$i])->pluck('id')->all());
        }
        $elementIds = $project->elements()->whereIn('location_id', $ids)->pluck('id')->all();

        return response()->json(['data' => $this->links($project, $ids, $elementIds)]);
    }

    /**
     * Diário e fotografias só para quem consulta o diário; documentos só para quem os consulta.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $elementIds
     * @return array<string, mixed>
     */
    private function links(Project $project, array $locationIds, array $elementIds): array
    {
        $match = fn ($q) => $q->where(fn ($w) => $w->whereIn('location_id', $locationIds ?: [0])->orWhereIn('element_id', $elementIds ?: [0]));
        $canDiary = Gate::allows('diary.view', $project);
        $canDocuments = Gate::allows('documents.view', $project);

        return [
            'diary_items' => $canDiary ? DiaryItem::query()->tap($match)
                ->whereHas('entry', fn ($q) => $q->where('project_id', $project->id))
                ->with('entry:id,entry_date,status')
                ->get()
                ->sortByDesc(fn (DiaryItem $i) => $i->entry->entry_date)
                ->values()
                ->map(fn (DiaryItem $i) => [
                    'id' => $i->id,
                    'entry_id' => $i->entry_id,
                    'entry_date' => $i->entry->entry_date->toDateString(),
                    'kind_label' => $i->kind->label(),
                    'description' => $i->description,
                    'quantity' => $i->quantity !== null ? (float) $i->quantity : null,
                    'unit' => $i->unit,
                    'company' => $i->company,
                    'location_id' => $i->location_id,
                    'element_id' => $i->element_id,
                ]) : null,
            'photos' => $canDiary ? MediaResource::collection(
                $project->media()->tap($match)->with(['uploadedBy:id,name', 'diaryEntry:id,entry_date'])->latest('id')->limit(200)->get()
            )->additional([]) : null,
            'documents' => $canDocuments ? DocumentResource::collection(
                $project->documents()->tap($match)->with('currentVersion.uploadedBy:id,name')->orderBy('title')->get()
            ) : null,
        ];
    }

    private function authorizeWrite(Project $project): void
    {
        $this->authorize('structure.manage', $project);

        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para alterar a estrutura.', 'PROJECT_ARCHIVED');
        }
    }

    /** @return array<string, mixed> */
    private function validateElement(Request $request, Project $project, ?ConstructionElement $element = null): array
    {
        $required = $element ? 'sometimes' : 'required';

        return $request->validate([
            'location_id' => [$required, 'integer', Rule::exists('project_locations', 'id')->where('project_id', $project->id)],
            'element_type' => [$required, Rule::enum(ElementType::class)],
            'code' => [$element ? 'sometimes' : 'nullable', $element ? 'required' : 'nullable', 'string', 'max:30',
                Rule::unique('construction_elements', 'code')->where('project_id', $project->id)->ignore($element?->id)],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'specialty_id' => Specialty::rules($element?->specialty_id),
        ], ['code.unique' => 'Já existe um elemento com este código nesta obra.'], [
            'location_id' => 'localização',
            'specialty_id' => 'especialidade',
            'element_type' => 'tipo',
            'code' => 'código',
        ]);
    }

    /** P1, P2... pelo maior número já usado com o mesmo prefixo (PA1 não conta para P). */
    private function nextCode(Project $project, ElementType $type): string
    {
        $prefix = $type->prefix();
        $max = $project->elements()->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn (string $code) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix.($max + 1);
    }

    /** @return array<string, mixed> */
    private function presentLocation(ProjectLocation $l): array
    {
        return [
            'id' => $l->id,
            'parent_id' => $l->parent_id,
            'kind' => $l->kind->value,
            'kind_label' => $l->kind->label(),
            'name' => $l->name,
            'sort_order' => $l->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function presentElement(ConstructionElement $e): array
    {
        return [
            'id' => $e->id,
            'location_id' => $e->location_id,
            'element_type' => $e->element_type->value,
            'element_type_label' => $e->element_type->label(),
            'code' => $e->code,
            'name' => $e->name,
            'notes' => $e->notes,
            'specialty_id' => $e->specialty_id,
        ];
    }
}
