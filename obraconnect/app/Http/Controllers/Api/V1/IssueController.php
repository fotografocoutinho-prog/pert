<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\InspectionResult;
use App\Enums\IssueKind;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\IssueResource;
use App\Http\Resources\MediaResource;
use App\Issues\IssueWorkflow;
use App\Media\MediaStorage;
use App\Models\InspectionItem;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Specialty;
use App\Notifications\Notifier;
use App\Structure\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ocorrências e não conformidades de uma obra. Registar: issues.create; resolver: issues.resolve
 * (o responsável); validar, reabrir e anular: issues.validate.
 */
class IssueController extends Controller
{
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const PHOTOS_PER_ISSUE = 30;

    private const DETAIL = [
        'specialty:id,code,name', 'assignee:id,name', 'resolvedBy:id,name', 'closedBy:id,name', 'createdBy:id,name',
        'inspection:id,number,title', 'photos.uploadedBy:id,name', 'events',
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly MediaStorage $media,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('issues.view', $project);

        $filters = $request->validate([
            'group' => ['nullable', Rule::in(['open', 'review', 'closed', 'overdue', 'all'])],
            'kind' => ['nullable', Rule::enum(IssueKind::class)],
            'mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'inspection_id' => ['nullable', 'integer'],
        ]);
        $group = $filters['group'] ?? 'all';
        $search = trim($filters['search'] ?? '');

        $issues = $project->issues()
            ->with(['specialty:id,code,name', 'assignee:id,name', 'inspection:id,number,title'])
            ->withCount('photos')
            ->when($group === 'overdue', fn ($q) => $q->overdue())
            ->when(! in_array($group, ['all', 'overdue'], true), fn ($q) => $q->whereIn('status', IssueStatus::group($group)))
            ->when(isset($filters['kind']), fn ($q) => $q->where('kind', $filters['kind']))
            ->when(! empty($filters['mine']), fn ($q) => $q->where('assignee_user_id', $request->user()->id))
            ->when(isset($filters['inspection_id']), fn ($q) => $q->where('inspection_id', $filters['inspection_id']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->when(ctype_digit($search), fn ($n) => $n->orWhere('number', (int) $search))))
            ->orderByDesc('number')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return IssueResource::collection($issues);
    }

    /** Quem pode ser responsável: as pessoas ativas da equipa da obra. */
    public function assignees(Project $project): JsonResponse
    {
        $this->authorize('issues.view', $project);

        return response()->json(['data' => $this->team($project)->map(fn (ProjectMember $m) => [
            'user_id' => $m->membership->user_id,
            'name' => $m->membership->user->name,
            'role' => $m->role->name,
        ])->sortBy(fn ($m) => mb_strtolower($m['name']))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('issues.create', $project);
        $this->assertProjectOpen($project);

        $data = $this->validated($request, $project);
        $item = isset($data['inspection_item_id']) ? InspectionItem::query()->with('inspection')->findOrFail($data['inspection_item_id']) : null;

        if ($item !== null && $item->result !== InspectionResult::Nok) {
            throw ValidationException::withMessages(['inspection_item_id' => 'Só se abre uma não conformidade a partir de um ponto não conforme.']);
        }
        if ($item === null && blank($data['title'] ?? null)) {
            throw ValidationException::withMessages(['title' => 'Escreva o título.']);
        }

        $issue = DB::transaction(function () use ($project, $data, $item, $request): Issue {
            // Bloqueia a obra enquanto numera.
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $place = $item
                ? ['location_id' => $item->inspection->location_id, 'element_id' => $item->inspection->element_id]
                : Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]);

            $issue = $project->issues()->make([
                'kind' => $data['kind'] ?? ($item ? IssueKind::NonConformity->value : IssueKind::Occurrence->value),
                'severity' => $data['severity'] ?? IssueSeverity::Minor->value,
                'title' => trim($data['title'] ?? '') ?: Str::limit($item->text, 200, ''),
                'description' => array_key_exists('description', $data) ? $data['description'] : $item?->notes,
                'specialty_id' => array_key_exists('specialty_id', $data) ? $data['specialty_id'] : $item?->inspection->specialty_id,
                'assignee_user_id' => $data['assignee_user_id'] ?? null,
                'due_on' => $data['due_on'] ?? null,
            ] + $place);
            $issue->number = (int) $project->issues()->max('number') + 1;
            $issue->inspection_id = $item?->inspection_id;
            $issue->inspection_item_id = $item?->id;
            $issue->created_by_user_id = $request->user()->id;
            $issue->save();

            IssueEvent::add($issue, 'created', $request->user(), null, array_filter([
                'inspection' => $item?->inspection->number,
                'assignee' => $issue->assignee?->name,
                'due_on' => $issue->due_on?->toDateString(),
            ]));

            return $issue;
        });

        $this->auditor->record('issue.created', $issue, ['project' => $project->code, 'reference' => $issue->reference()]);
        if ($issue->assignee_user_id !== null) {
            $this->notifier->issueAssigned($project, $issue, $request->user());
        }

        return $this->detail($project, $issue)->response()->setStatusCode(201);
    }

    public function show(Project $project, Issue $issue): IssueResource
    {
        $this->authorize('issues.view', $project);

        return $this->detail($project, $issue);
    }

    public function update(Request $request, Project $project, Issue $issue): IssueResource
    {
        $this->authorize('issues.create', $project);
        $this->assertProjectOpen($project);
        if ($issue->status->isFinished()) {
            throw ApiException::conflict("A {$issue->reference()} está «{$issue->status->label()}»: reabra-a para a alterar.", 'ISSUE_STATUS');
        }
        abort_unless(Gate::allows('issues.validate', $project) || $issue->created_by_user_id === $request->user()->id, 403);

        $data = $this->validated($request, $project, $issue);
        $before = ['assignee' => $issue->assignee_user_id, 'due_on' => $issue->due_on?->toDateString()];

        DB::transaction(function () use ($project, $issue, $data, $before, $request): void {
            $issue->fill(array_intersect_key($data, array_flip(['kind', 'severity', 'title', 'description', 'specialty_id', 'assignee_user_id', 'due_on'])));
            if (array_key_exists('location_id', $data) || array_key_exists('element_id', $data)) {
                $issue->fill(Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]));
            }
            $dueChanged = $issue->due_on?->toDateString() !== $before['due_on'];
            if ($dueChanged) {
                // Prazo novo: volta a poder ser escalonada quando passar.
                $issue->escalated_at = null;
            }
            $issue->save();

            if ($issue->assignee_user_id !== $before['assignee']) {
                IssueEvent::add($issue, 'assigned', $request->user(), null, ['assignee' => $issue->assignee()->value('name')]);
            }
            if ($dueChanged) {
                IssueEvent::add($issue, 'due_changed', $request->user(), null, ['from' => $before['due_on'], 'to' => $issue->due_on?->toDateString()]);
            }
        });

        $changes = $this->auditor->changes($issue);
        if ($changes['new'] !== []) {
            $this->auditor->record('issue.updated', $issue, $changes);
        }
        if ($issue->assignee_user_id !== null && $issue->assignee_user_id !== $before['assignee']) {
            $this->notifier->issueAssigned($project, $issue, $request->user());
        }

        return $this->detail($project, $issue);
    }

    /** start, resolve, close, reopen, cancel. */
    public function transition(Request $request, Project $project, Issue $issue, string $action): IssueResource
    {
        $definition = IssueWorkflow::actions()[$action] ?? abort(404);
        $this->authorize($definition['permission'], $project);
        $this->assertProjectOpen($project);
        $user = $request->user();

        // Resolver é do responsável (ou de quem também valida); sem responsável, de quem pode resolver.
        if ($definition['permission'] === 'issues.resolve' && $issue->assignee_user_id !== null && $issue->assignee_user_id !== $user->id
            && ! Gate::allows('issues.validate', $project)) {
            throw ApiException::forbidden("A {$issue->reference()} tem outro responsável.", 'NOT_ASSIGNEE');
        }

        $reason = $request->validate([
            'reason' => [$definition['reason'] ? 'required' : 'nullable', 'string', 'max:5000'],
        ], [], ['reason' => $action === 'resolve' ? 'o que foi feito' : 'motivo'])['reason'] ?? null;
        $reason = filled($reason) ? trim($reason) : null;

        DB::transaction(function () use ($issue, $action, $definition, $reason, $user): void {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
            if (! in_array($locked->status, $definition['from'], true)) {
                throw ApiException::conflict("A {$locked->reference()} está «{$locked->status->label()}»: não é possível ".mb_strtolower($definition['label']).'.', 'ISSUE_STATUS');
            }

            match ($action) {
                'resolve' => $locked->forceFill(['resolution_note' => $reason, 'resolved_by_user_id' => $user->id, 'resolved_at' => now()]),
                'close', 'cancel' => $locked->forceFill(['closed_by_user_id' => $user->id, 'closed_at' => now()]),
                // A resolução anterior fica no historial; volta a contar para o prazo e o escalonamento.
                'reopen' => $locked->forceFill([
                    'resolution_note' => null, 'resolved_by_user_id' => null, 'resolved_at' => null,
                    'closed_by_user_id' => null, 'closed_at' => null, 'escalated_at' => null,
                ]),
                default => null,
            };
            $locked->status = $definition['to'];
            $locked->save();
            IssueEvent::add($locked, $action, $user, $reason);
        });

        $issue->refresh();
        $this->auditor->record("issue.{$action}", $issue, array_filter([
            'project' => $project->code,
            'reference' => $issue->reference(),
            'reason' => $reason,
        ]));

        match ($action) {
            'resolve' => $this->notifier->issueResolved($project, $issue, $user),
            'reopen' => $this->notifier->issueReopened($project, $issue, $user, $reason),
            default => null,
        };

        return $this->detail($project, $issue);
    }

    public function comment(Request $request, Project $project, Issue $issue): IssueResource
    {
        abort_unless(Gate::any(IssueWorkflow::PARTICIPATE, $project), 403);
        $this->assertProjectOpen($project);

        $body = trim($request->validate(['body' => ['required', 'string', 'max:5000']], [], ['body' => 'comentário'])['body']);
        IssueEvent::add($issue, 'comment', $request->user(), $body);

        return $this->detail($project, $issue);
    }

    public function storePhoto(Request $request, Project $project, Issue $issue): JsonResponse
    {
        $this->authorizePhotos($project, $issue);

        $maxMb = (int) config('obraconnect.files.photo_max_mb');
        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.($maxMb * 1024)],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photo.mimetypes' => 'Só são aceites fotografias JPEG, PNG ou WebP.',
            'photo.max' => "A fotografia não pode ter mais de {$maxMb} MB.",
        ], ['photo' => 'fotografia', 'caption' => 'legenda']);

        $info = @getimagesize($request->file('photo')->getRealPath());
        if (! $info || ! in_array($info['mime'], self::TYPES, true)) {
            throw ValidationException::withMessages(['photo' => 'Só são aceites fotografias JPEG, PNG ou WebP.']);
        }
        if ($issue->photos()->count() >= self::PHOTOS_PER_ISSUE) {
            throw ValidationException::withMessages(['photo' => 'Cada ocorrência pode ter no máximo '.self::PHOTOS_PER_ISSUE.' fotografias.']);
        }

        $photo = $this->media->storeIssuePhoto($project, $issue, $request->file('photo'), $info['mime'], $request->input('caption'), $request->user());
        $this->auditor->record('issue.photo_added', $issue, ['project' => $project->code, 'reference' => $issue->reference(), 'sha256' => $photo->sha256]);

        return response()->json(['data' => (new MediaResource($photo->refresh()->load('uploadedBy:id,name')))->resolve() + ['can_remove' => true]], 201);
    }

    public function destroyPhoto(Request $request, Project $project, Issue $issue, Media $photo): Response
    {
        $this->authorizePhotos($project, $issue);
        abort_unless($photo->uploaded_by_user_id === $request->user()->id || Gate::allows('issues.validate', $project), 403);

        $this->media->delete($photo);
        $this->auditor->record('issue.photo_removed', $issue, ['project' => $project->code, 'reference' => $issue->reference(), 'file' => $photo->original_name]);

        return response()->noContent();
    }

    private function authorizePhotos(Project $project, Issue $issue): void
    {
        abort_unless(Gate::any(IssueWorkflow::PARTICIPATE, $project), 403);
        $this->assertProjectOpen($project);
        if ($issue->status->isFinished()) {
            throw ApiException::conflict("A {$issue->reference()} está «{$issue->status->label()}»: já não se juntam fotografias.", 'ISSUE_STATUS');
        }
    }

    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para mexer nas ocorrências.', 'PROJECT_ARCHIVED');
        }
    }

    private function detail(Project $project, Issue $issue): IssueResource
    {
        $issue->setRelation('project', $project);

        return new IssueResource($issue->load(self::DETAIL));
    }

    /** @return Collection<int, ProjectMember> */
    private function team(Project $project)
    {
        return $project->members()->with(['membership.user:id,name,email', 'role:id,name'])->get()
            ->filter(fn (ProjectMember $m) => $m->membership->isActive());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Issue $issue = null): array
    {
        $creating = $issue === null;
        $teamUserIds = $this->team($project)->map(fn (ProjectMember $m) => $m->membership->user_id)->all();
        $today = now('Europe/Lisbon')->toDateString();

        return $request->validate([
            'kind' => ['sometimes', Rule::enum(IssueKind::class)],
            'severity' => ['sometimes', Rule::enum(IssueSeverity::class)],
            'title' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:200', ...($creating ? [] : ['required'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'specialty_id' => Specialty::rules($issue?->specialty_id),
            'assignee_user_id' => ['sometimes', 'nullable', 'integer', Rule::in($teamUserIds)],
            // Prazo no passado só se já lá estava (não se cria uma ocorrência já atrasada).
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail) use ($issue, $today): void {
                if ($value !== null && $value < $today && $value !== $issue?->due_on?->toDateString()) {
                    $fail('O prazo não pode ser no passado.');
                }
            }],
            'inspection_item_id' => $creating
                ? ['nullable', 'integer', Rule::exists('inspection_items', 'id')->where(fn ($q) => $q->whereIn('inspection_id', $project->inspections()->select('id')))]
                : ['prohibited'],
        ] + Place::rules($project), [
            'assignee_user_id.in' => 'O responsável tem de estar na equipa da obra.',
        ], [
            'kind' => 'tipo',
            'severity' => 'gravidade',
            'title' => 'título',
            'description' => 'descrição',
            'specialty_id' => 'especialidade',
            'assignee_user_id' => 'responsável',
            'due_on' => 'prazo',
            'inspection_item_id' => 'ponto da inspeção',
        ]);
    }
}
