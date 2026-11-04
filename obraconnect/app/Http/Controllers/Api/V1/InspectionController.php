<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsInspectionWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\InspectionResource;
use App\Inspections\ChecklistItems;
use App\Inspections\EvidenceIndex;
use App\Inspections\InspectionHistory;
use App\Inspections\InspectionWorkflow;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Project;
use App\Models\Specialty;
use App\Structure\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inspeções de uma obra. Em rascunho preparam-se (inspections.request); depois só mudam de
 * estado pelas passagens de InspectionWorkflow, cada uma registada no histórico encadeado.
 */
class InspectionController extends Controller
{
    use GuardsInspectionWrites;

    private const LIST = ['specialty:id,code,name', 'template:id,name', 'requestedBy:id,name', 'inspector:id,name'];

    private const DETAIL = [
        'items.photos.uploadedBy:id,name', 'items.answeredBy:id,name', 'events',
        'specialty:id,code,name', 'template:id,name', 'reinspectionOf:id,number', 'reinspections:id,number,status,reinspection_of_id',
        'requestedBy:id,name', 'inspector:id,name', 'decidedBy:id,name', 'createdBy:id,name',
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly InspectionHistory $history,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($project);

        $filters = $request->validate([
            'group' => ['nullable', Rule::in(['open', 'review', 'closed', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');

        $inspections = $project->inspections()
            ->with(self::LIST)
            ->withCount([
                'items',
                'items as answered_count' => fn ($q) => $q->whereNotNull('result'),
                'items as nok_count' => fn ($q) => $q->where('result', InspectionResult::Nok->value),
            ])
            ->when(($filters['group'] ?? 'all') !== 'all', fn ($q) => $q->whereIn('status', InspectionStatus::group($filters['group'])))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->when(ctype_digit($search), fn ($n) => $n->orWhere('number', (int) $search))))
            ->orderByDesc('number')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return InspectionResource::collection($inspections);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('inspections.request', $project);
        $this->assertProjectOpen($project);

        $data = $this->validated($request, $project);
        $template = isset($data['checklist_template_id']) ? ChecklistTemplate::query()->with('items')->find($data['checklist_template_id']) : null;
        $items = isset($data['items'])
            ? ChecklistItems::normalize($data['items'])
            : $template?->items->values()->map(fn (ChecklistTemplateItem $item, int $i) => $item->only(ChecklistTemplateItem::COPIED) + ['sort_order' => $i])->all();

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'Escolha uma lista de verificação ou escreva pelo menos um ponto a verificar.']);
        }

        $inspection = DB::transaction(function () use ($project, $data, $template, $items, $request): Inspection {
            $number = $this->nextNumber($project);
            $inspection = $project->inspections()->make($this->headerFields($project, $data) + [
                'title' => trim($data['title'] ?? '') ?: ($template?->name ?? "Inspeção n.º {$number}"),
                'specialty_id' => array_key_exists('specialty_id', $data) ? $data['specialty_id'] : $template?->specialty_id,
            ]);
            $inspection->number = $number;
            $inspection->checklist_template_id = $template?->id;
            $inspection->template_version = $template?->version;
            $inspection->created_by_user_id = $request->user()->id;
            $inspection->save();
            $inspection->items()->createMany($items);

            $this->history->record($inspection, 'created', null, InspectionStatus::Draft, $request->user(), null, [
                'number' => $inspection->number,
                'title' => $inspection->title,
                'template' => $template ? ['id' => $template->id, 'name' => $template->name, 'version' => $template->version] : null,
            ]);

            return $inspection;
        });

        $this->auditor->record('inspection.created', $inspection, ['project' => $project->code, 'number' => $inspection->number]);

        return $this->detail($project, $inspection)->response()->setStatusCode(201);
    }

    public function show(Project $project, Inspection $inspection): InspectionResource
    {
        $this->authorizeView($project);

        return $this->detail($project, $inspection);
    }

    /** Só em rascunho: cabeçalho e pontos (lista inteira). */
    public function update(Request $request, Project $project, Inspection $inspection): InspectionResource
    {
        $this->authorize('inspections.request', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($inspection, [InspectionStatus::Draft], 'alterar a preparação');

        $data = $this->validated($request, $project, $inspection);

        DB::transaction(function () use ($project, $inspection, $data): void {
            $inspection->fill($this->headerFields($project, $data));
            if (isset($data['title'])) {
                $inspection->title = trim($data['title']);
            }
            if (array_key_exists('specialty_id', $data)) {
                $inspection->specialty_id = $data['specialty_id'];
            }
            $inspection->save();

            if (array_key_exists('items', $data)) {
                $inspection->items()->delete();
                $inspection->items()->createMany(ChecklistItems::normalize($data['items']));
            }
        });

        return $this->detail($project, $inspection);
    }

    /** Só rascunhos (sem evidências nem decisões). Pedidas em diante: cancelam-se. */
    public function destroy(Project $project, Inspection $inspection): Response
    {
        $this->authorize('inspections.request', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($inspection, [InspectionStatus::Draft], 'apagar (cancele-a)');

        $inspection->delete();
        $this->auditor->record('inspection.deleted', $project, ['number' => $inspection->number, 'title' => $inspection->title]);

        return response()->noContent();
    }

    /** Passagem de estado: request, schedule, start, submit, return, approve, approve_with_reservations, reject, cancel. */
    public function transition(Request $request, Project $project, Inspection $inspection, string $action): InspectionResource
    {
        $definition = InspectionWorkflow::actions()[$action] ?? abort(404);
        abort_unless(Gate::any($definition['permissions'], $project), 403);
        $this->assertProjectOpen($project);

        $data = $request->validate([
            'reason' => [$definition['reason'] ? 'required' : 'nullable', 'string', 'max:2000'],
            'scheduled_for' => [$action === 'schedule' ? 'required' : 'prohibited', 'date', 'after_or_equal:today'],
        ], ['scheduled_for.after_or_equal' => 'A data da inspeção não pode ser no passado.'], [
            'reason' => $action === 'cancel' ? 'motivo' : 'justificação',
            'scheduled_for' => 'data da inspeção',
        ]);
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;
        $user = $request->user();

        DB::transaction(function () use ($inspection, $action, $definition, $data, $reason, $user): void {
            // Bloqueia a linha: duas decisões ao mesmo tempo não passam as duas.
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            $this->assertStatus($locked, $definition['from'], mb_strtolower($definition['label']));
            $locked->load('items.photos');
            $from = $locked->status;
            $payload = null;

            switch ($action) {
                case 'request':
                    $locked->requested_by_user_id = $user->id;
                    $locked->requested_at = now();
                    $payload = ['items' => $this->snapshotRequested($locked)];
                    break;
                case 'schedule':
                    $locked->scheduled_for = $data['scheduled_for'];
                    $locked->inspector_user_id = $user->id;
                    $payload = ['scheduled_for' => $locked->scheduled_for->format('Y-m-d H:i')];
                    break;
                case 'start':
                    $locked->started_at ??= now();
                    $locked->inspector_user_id = $user->id;
                    break;
                case 'submit':
                    $evidence = EvidenceIndex::evaluate($locked);
                    if (! $evidence['can_submit']) {
                        throw new ApiException(
                            'Faltam evidências obrigatórias: '.$this->describeMissing($evidence['criteria']).'.',
                            'EVIDENCE_MISSING', 422, ['criteria' => $evidence['criteria']],
                        );
                    }
                    $locked->submitted_at = now();
                    $locked->evidence_score = $evidence['score'];
                    $payload = ['evidence_score' => $evidence['score'], 'results' => $this->snapshotResults($locked)];
                    break;
                case 'return':
                    $locked->evidence_score = null;
                    break;
                case 'approve':
                    if ($locked->items->contains(fn (InspectionItem $i) => $i->result === InspectionResult::Nok)) {
                        throw ApiException::conflict('Há pontos não conformes: aprove com reservas ou rejeite.', 'INSPECTION_HAS_NOK');
                    }
                    // no break
                case 'approve_with_reservations':
                case 'reject':
                case 'cancel':
                    $locked->decided_by_user_id = $user->id;
                    $locked->decided_at = now();
                    $locked->decision_note = $reason;
                    $payload = $action === 'cancel' ? null : ['evidence_score' => $locked->evidence_score];
                    break;
            }

            $locked->status = $definition['to'];
            $locked->save();
            $this->history->record($locked, $action, $from, $locked->status, $user, $reason, $payload);
        });

        $inspection->refresh();
        $this->auditor->record("inspection.{$action}", $inspection, array_filter([
            'project' => $project->code,
            'number' => $inspection->number,
            'status' => $inspection->status->value,
            'reason' => $reason,
        ]));

        return $this->detail($project, $inspection);
    }

    /** Nova inspeção (rascunho) com os mesmos pontos, depois de rejeitada ou aprovada com reservas. */
    public function reinspect(Request $request, Project $project, Inspection $inspection): JsonResponse
    {
        $this->authorize('inspections.request', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($inspection, [InspectionStatus::Rejected, InspectionStatus::ApprovedWithReservations], 'pedir reinspeção');

        $copy = DB::transaction(function () use ($project, $inspection, $request): Inspection {
            Inspection::query()->lockForUpdate()->findOrFail($inspection->id);

            $copy = $project->inspections()->make($inspection->only(['description', 'location_id', 'element_id', 'specialty_id', 'remote']));
            $copy->title = Str::limit("Reinspeção da n.º {$inspection->number}: {$inspection->title}", 200, '');
            $copy->number = $this->nextNumber($project);
            $copy->checklist_template_id = $inspection->checklist_template_id;
            $copy->template_version = $inspection->template_version;
            $copy->reinspection_of_id = $inspection->id;
            $copy->created_by_user_id = $request->user()->id;
            $copy->save();
            $copy->items()->createMany($inspection->items->values()
                ->map(fn (InspectionItem $item, int $i) => $item->only(ChecklistTemplateItem::COPIED) + ['sort_order' => $i])->all());

            $this->history->record($copy, 'created', null, InspectionStatus::Draft, $request->user(), null, [
                'number' => $copy->number,
                'title' => $copy->title,
                'reinspection_of' => $inspection->number,
            ]);
            $this->history->record($inspection, 'reinspection', $inspection->status, $inspection->status, $request->user(), null, [
                'reinspection_number' => $copy->number,
            ]);

            return $copy;
        });

        $this->auditor->record('inspection.reinspection', $copy, ['project' => $project->code, 'number' => $copy->number, 'of' => $inspection->number]);

        return $this->detail($project, $copy)->response()->setStatusCode(201);
    }

    private function detail(Project $project, Inspection $inspection): InspectionResource
    {
        $inspection->setRelation('project', $project);

        return new InspectionResource($inspection->load(self::DETAIL));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Inspection $inspection = null): array
    {
        $creating = $inspection === null;

        return $request->validate([
            'title' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:200', ...($creating ? [] : ['required'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'checklist_template_id' => $creating
                ? ['nullable', 'integer', Rule::exists('checklist_templates', 'id')->where('tenant_id', $project->tenant_id)->whereNull('archived_at')]
                : ['prohibited'],
            'specialty_id' => Specialty::rules($inspection?->specialty_id),
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'remote' => ['sometimes', 'boolean'],
        ] + Place::rules($project) + ChecklistItems::rules('items', required: false), [
            'checklist_template_id.exists' => 'Essa lista de verificação não existe ou está arquivada.',
        ], [
            'title' => 'título',
            'description' => 'descrição',
            'checklist_template_id' => 'lista de verificação',
            'specialty_id' => 'especialidade',
            'scheduled_for' => 'data pretendida',
            'items' => 'pontos a verificar',
        ] + ChecklistItems::attributes());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerFields(Project $project, array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['description', 'scheduled_for', 'remote']));
        if (array_key_exists('location_id', $data) || array_key_exists('element_id', $data)) {
            $place = Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]);
            $fields['location_id'] = $place['location_id'];
            $fields['element_id'] = $place['element_id'];
        }

        return $fields;
    }

    private function nextNumber(Project $project): int
    {
        // Bloqueia a obra enquanto numera: duas inspeções criadas ao mesmo tempo não ficam com o mesmo número.
        Project::query()->whereKey($project->id)->lockForUpdate()->first();

        return (int) $project->inspections()->max('number') + 1;
    }

    /** @return list<array<string, mixed>> */
    private function snapshotRequested(Inspection $inspection): array
    {
        return $inspection->items->values()->map(fn (InspectionItem $item, int $i) => [
            'n' => $i + 1,
            'text' => $item->text,
            'photos_required' => $item->photosRequired(),
            'measure' => $item->requires_measure ? array_filter([
                'unit' => $item->measure_unit,
                'min' => $item->measure_min,
                'max' => $item->measure_max,
            ], fn ($v) => $v !== null) : null,
        ])->all();
    }

    /** O que foi verificado, com o SHA-256 de cada fotografia: prova do que estava submetido. */
    private function snapshotResults(Inspection $inspection): array
    {
        return $inspection->items->values()->map(fn (InspectionItem $item, int $i) => [
            'n' => $i + 1,
            'result' => $item->result?->value,
            'measure_value' => $item->measure_value,
            'notes' => $item->notes,
            'photos' => $item->photos->pluck('sha256')->all(),
        ])->all();
    }

    /** @param  list<array{label: string, required: bool, met: int, total: int, missing: list<int>}>  $criteria */
    private function describeMissing(array $criteria): string
    {
        return collect($criteria)
            ->filter(fn ($c) => $c['required'] && $c['met'] < $c['total'])
            ->map(fn ($c) => mb_strtolower($c['label']).' (pontos '.implode(', ', $c['missing']).')')
            ->implode('; ');
    }
}
