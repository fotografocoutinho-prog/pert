<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\EquipmentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentUsage;
use App\Models\MaintenanceRecord;
use App\Models\Project;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Equipamentos da empresa: ficha, obra onde está, horas de utilização por obra (custo/hora copiado),
 * manutenções e etiqueta QR que abre a ficha (só com sessão iniciada). Gerir: equipment.manage.
 */
class EquipmentController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        $this->authorizeAny(['equipment.manage', 'costs.view', 'workforce.view']);

        return response()->json(['data' => Equipment::query()->with('project:id,code,name')->orderBy('code')->get()
            ->map(fn (Equipment $e) => $this->summary($e))->values()]);
    }

    public function show(Equipment $equipment): JsonResponse
    {
        $this->authorizeAny(['equipment.manage', 'costs.view', 'workforce.view']);

        return response()->json(['data' => $this->present($equipment)]);
    }

    /** Pelo código da etiqueta QR (a ficha abre-se a partir do telemóvel). */
    public function byToken(string $token): JsonResponse
    {
        $this->authorizeAny(['equipment.manage', 'costs.view', 'workforce.view']);
        $equipment = Equipment::query()->where('qr_token', $token)->firstOrFail();

        return response()->json(['data' => $this->present($equipment)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('equipment.manage');
        $equipment = Equipment::query()->create($this->validated($request));
        $this->auditor->record('equipment.created', $equipment, ['code' => $equipment->code]);

        return response()->json(['data' => $this->present($equipment)], 201);
    }

    public function update(Request $request, Equipment $equipment): JsonResponse
    {
        $this->authorize('equipment.manage');
        $data = $this->validated($request, $equipment);
        // Na obra = "em obra"; sem obra, volta a "disponível" (manutenção e abate ficam como estão).
        if (array_key_exists('project_id', $data) && ! array_key_exists('status', $data) && in_array($equipment->status, [EquipmentStatus::Available, EquipmentStatus::InUse], true)) {
            $data['status'] = $data['project_id'] ? EquipmentStatus::InUse->value : EquipmentStatus::Available->value;
        }
        $equipment->fill($data)->save();

        return response()->json(['data' => $this->present($equipment)]);
    }

    public function destroy(Equipment $equipment): Response
    {
        $this->authorize('equipment.manage');
        if ($equipment->usages()->exists()) {
            throw ApiException::conflict('Tem utilização registada. Marque-o como abatido em vez de o apagar.', 'EQUIPMENT_IN_USE');
        }
        $equipment->delete();

        return response()->noContent();
    }

    /** Etiqueta QR (SVG) com o endereço da ficha. */
    public function qr(Equipment $equipment): Response
    {
        $this->authorizeAny(['equipment.manage', 'costs.view', 'workforce.view']);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($this->scanUrl($equipment));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function storeMaintenance(Request $request, Equipment $equipment): JsonResponse
    {
        $this->authorize('equipment.manage');
        $data = $request->validate([
            'performed_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after:performed_on'],
        ], [], ['performed_on' => 'data', 'description' => 'descrição', 'next_due_on' => 'próxima']);
        $equipment->maintenance()->create($data + ['cost_cents' => 0]);

        return response()->json(['data' => $this->present($equipment)], 201);
    }

    public function destroyMaintenance(Equipment $equipment, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $this->authorize('equipment.manage');
        $maintenanceRecord->delete();

        return response()->json(['data' => $this->present($equipment)]);
    }

    // ---- Utilização numa obra ----

    public function projectUsages(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAny(['equipment.manage', 'costs.view', 'workforce.view'], $project);

        return response()->json(['data' => [
            'usages' => $project->equipmentUsages()->with(['equipment:id,code,name', 'createdBy:id,name'])->orderByDesc('used_on')->limit(300)->get()
                ->map(fn (EquipmentUsage $u) => $this->presentUsage($u))->all(),
            'on_site' => Equipment::query()->where('project_id', $project->id)->orderBy('code')->get()->map(fn (Equipment $e) => $this->summary($e))->all(),
            'can_register' => Gate::allows('equipment.manage', $project) && ! $project->isArchived(),
        ]]);
    }

    public function storeUsage(Request $request, Project $project): JsonResponse
    {
        $this->authorize('equipment.manage', $project);
        $this->assertProjectOpen($project);
        $data = $request->validate([
            'equipment_id' => ['required', 'integer', Rule::exists('equipment', 'id')->where('tenant_id', $this->tenantContext()->id())->where('status', '!=', EquipmentStatus::Retired->value)],
            'used_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Lisbon')->toDateString()],
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['equipment_id' => 'equipamento', 'used_on' => 'dia', 'hours' => 'horas']);
        $equipment = Equipment::query()->findOrFail($data['equipment_id']);

        $usage = $project->equipmentUsages()->make([
            'used_on' => $data['used_on'], 'hours' => self::decimal($data['hours']), 'notes' => $data['notes'] ?? null,
        ]);
        $usage->equipment_id = $equipment->id;
        $usage->hourly_cost_cents = $equipment->hourly_cost_cents;
        $usage->created_by_user_id = $request->user()->id;
        $usage->save();

        return response()->json(['data' => $this->presentUsage($usage->load(['equipment:id,code,name', 'createdBy:id,name']))], 201);
    }

    public function destroyUsage(Project $project, EquipmentUsage $equipmentUsage): Response
    {
        $this->authorize('equipment.manage', $project);
        $this->assertProjectOpen($project);
        $equipmentUsage->delete();

        return response()->noContent();
    }

    private function scanUrl(Equipment $equipment): string
    {
        return url("/app/equipamentos/qr/{$equipment->qr_token}");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Equipment $equipment = null): array
    {
        $tenant = $this->tenantContext()->id();

        return $request->validate([
            'code' => [$equipment ? 'sometimes' : 'required', 'string', 'max:30', Rule::unique('equipment', 'code')->where('tenant_id', $tenant)->ignore($equipment?->id)],
            'name' => [$equipment ? 'sometimes' : 'required', 'string', 'max:150'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hourly_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'status' => ['sometimes', Rule::enum(EquipmentStatus::class)],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenant)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [], ['code' => 'código', 'name' => 'nome', 'hourly_cost_cents' => 'custo/hora', 'project_id' => 'obra']);
    }

    /** @return array<string, mixed> */
    private function summary(Equipment $e): array
    {
        $next = $e->maintenance()->whereNotNull('next_due_on')->orderByDesc('performed_on')->value('next_due_on');

        return [
            'id' => $e->id,
            'code' => $e->code,
            'name' => $e->name,
            'category' => $e->category,
            'serial_number' => $e->serial_number,
            'hourly_cost_cents' => $e->hourly_cost_cents,
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'project' => $e->project ? ['id' => $e->project->id, 'code' => $e->project->code, 'name' => $e->project->name] : null,
            'project_id' => $e->project_id,
            'next_maintenance_on' => $next ? substr((string) $next, 0, 10) : null,
            'maintenance_due' => $next !== null && substr((string) $next, 0, 10) <= now('Europe/Lisbon')->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Equipment $equipment): array
    {
        $equipment->load(['project:id,code,name', 'usages.project:id,code', 'maintenance']);

        return $this->summary($equipment) + [
            'notes' => $equipment->notes,
            'qr_url' => $this->scanUrl($equipment),
            'hours_total' => (float) $equipment->usages->sum(fn ($u) => (float) $u->hours),
            'usages' => $equipment->usages->take(50)->map(fn (EquipmentUsage $u) => [
                'id' => $u->id, 'used_on' => $u->used_on->toDateString(), 'hours' => (float) $u->hours, 'project' => $u->project?->code, 'notes' => $u->notes,
            ])->values()->all(),
            'maintenance' => $equipment->maintenance->map(fn (MaintenanceRecord $m) => [
                'id' => $m->id, 'performed_on' => $m->performed_on->toDateString(), 'description' => $m->description,
                'cost_cents' => $m->cost_cents, 'next_due_on' => $m->next_due_on?->toDateString(),
            ])->all(),
            'can_manage' => Gate::allows('equipment.manage'),
        ];
    }

    /** @return array<string, mixed> */
    private function presentUsage(EquipmentUsage $u): array
    {
        return [
            'id' => $u->id,
            'equipment' => $u->equipment ? ['id' => $u->equipment->id, 'code' => $u->equipment->code, 'name' => $u->equipment->name] : null,
            'used_on' => $u->used_on->toDateString(),
            'hours' => (float) $u->hours,
            'cost_cents' => $u->costCents(),
            'notes' => $u->notes,
            'created_by' => $u->createdBy?->name,
        ];
    }
}
