<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PlanLimits $limits,
        private readonly AccessResolver $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('projects.view');

        // Sessão de suporte (sem associação): vê todas as obras, só em leitura.
        $visible = $this->membership() ? $this->access->visibleProjectIds($this->membership()) : null;
        $search = trim((string) $request->query('search'));

        $projects = Project::query()
            ->withCount('members')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('code', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('city', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($request->query('status') === 'open', fn ($q) => $q->whereIn('status', ProjectStatus::activeValues()))
            ->when(
                $request->query('status') && $request->query('status') !== 'open',
                fn ($q) => $q->where('status', (string) $request->query('status')),
            )
            ->orderByRaw('case when status in (?, ?) then 1 else 0 end', [ProjectStatus::Completed->value, ProjectStatus::Archived->value])
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ProjectResource::collection($projects);
    }

    public function store(Request $request): ProjectResource
    {
        $this->authorize('projects.create');

        $data = $this->validated($request);
        $data['status'] ??= ProjectStatus::Planning->value;
        $tenant = $this->tenantContext()->tenantOrFail();

        if (ProjectStatus::from($data['status'])->countsAsActive()) {
            $this->limits->assertCanAdd($tenant, 'max_active_projects');
        }

        $project = DB::transaction(function () use ($data, $request): Project {
            $project = new Project($data);
            $project->code = filled($data['code'] ?? null) ? $data['code'] : $this->nextCode();
            $project->created_by_user_id = $request->user()->id;
            $project->save();

            // Quem cria fica na equipa, para continuar a ver a obra mesmo sem acesso a todas.
            if ($membership = $this->membership()) {
                $project->members()->create([
                    'membership_id' => $membership->id,
                    'role_id' => $membership->role_id,
                    'added_by_user_id' => $request->user()->id,
                ]);
                $this->access->flush();
            }

            return $project;
        });

        $this->auditor->record('project.created', $project, ['code' => $project->code, 'name' => $project->name]);

        return new ProjectResource($project->loadCount('members'));
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('projects.view', $project);

        return new ProjectResource($project->loadCount('members'));
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $data = $this->validated($request, $project);
        $newStatus = isset($data['status']) ? ProjectStatus::from($data['status']) : $project->status;
        $statusChanged = $newStatus !== $project->status;
        $otherFields = array_diff_key($data, ['status' => true]);
        $closingOrReopening = $statusChanged && ($newStatus->isClosed() || $project->status->isClosed());

        // Concluir, arquivar ou reabrir é uma decisão à parte de editar os dados.
        if ($closingOrReopening) {
            $this->authorize('projects.archive', $project);
        }
        if ($otherFields !== [] || ! $closingOrReopening) {
            $this->authorize('projects.update', $project);
        }
        if ($project->isArchived() && $otherFields !== []) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para alterar os dados.', 'PROJECT_ARCHIVED');
        }
        if ($statusChanged && $newStatus->countsAsActive() && ! $project->status->countsAsActive()) {
            $this->limits->assertCanAdd($this->tenantContext()->tenantOrFail(), 'max_active_projects');
        }

        $project->fill($data);
        if (array_key_exists('code', $data) && blank($data['code'])) {
            $project->code = $project->getOriginal('code');
        }
        $project->save();

        $changes = $this->auditor->changes($project);
        if ($changes['new'] !== []) {
            $this->auditor->record($statusChanged ? 'project.status_changed' : 'project.updated', $project, $changes);
        }

        return new ProjectResource($project->loadCount('members'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Project $project = null): array
    {
        $tenantId = $this->tenantContext()->id();
        $required = $project ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'code' => ['sometimes', 'nullable', 'string', 'max:30',
                Rule::unique('projects', 'code')->where('tenant_id', $tenantId)->ignore($project?->id)],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'type' => ['sometimes', 'nullable', Rule::enum(ProjectType::class)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contract_value_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999999999999'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'planned_end_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], [
            'name' => 'nome da obra',
            'code' => 'código',
            'owner_name' => 'dono de obra',
            'contract_value_cents' => 'valor da adjudicação',
            'starts_on' => 'data de início',
            'planned_end_on' => 'data de fim prevista',
        ]);

        // Compara com a data guardada quando o pedido só altera uma das duas.
        $start = array_key_exists('starts_on', $data) ? $data['starts_on'] : $project?->starts_on?->toDateString();
        $end = array_key_exists('planned_end_on', $data) ? $data['planned_end_on'] : $project?->planned_end_on?->toDateString();
        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages(['planned_end_on' => 'A data de fim prevista não pode ser anterior à data de início.']);
        }

        return $data;
    }

    /** OB-2026-001, OB-2026-002, ... por empresa e por ano. */
    private function nextCode(): string
    {
        $prefix = 'OB-'.now()->format('Y').'-';
        $n = Project::query()->where('code', 'like', $prefix.'%')->count() + 1;

        while (Project::query()->where('code', $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
