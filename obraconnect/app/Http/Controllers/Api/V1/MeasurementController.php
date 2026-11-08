<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\BudgetStatus;
use App\Enums\CertificateStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\CertificateLine;
use App\Models\Measurement;
use App\Models\Project;
use App\Structure\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Medições do executado, sobre os artigos do orçamento aprovado da obra. Uma medição que já entrou
 * num auto não se altera. Registar: measurements.manage.
 */
class MeasurementController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAny(['measurements.manage', 'certificates.approve', 'budgets.view', 'costs.view'], $project);
        $filters = $request->validate([
            'budget_item_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $budget = $this->approvedBudget($project, required: false);
        $measurements = $project->measurements()
            ->with(['item:id,code,description,unit', 'certificate:id,number,status', 'createdBy:id,name'])
            ->when(isset($filters['budget_item_id']), fn ($q) => $q->where('budget_item_id', $filters['budget_item_id']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('measured_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('measured_on', '<=', $filters['to']))
            ->orderByDesc('measured_on')->orderByDesc('id')
            ->limit(500)->get();

        return response()->json(['data' => [
            'budget' => $budget ? ['id' => $budget->id, 'code' => $budget->code, 'version' => $budget->version, 'name' => $budget->name] : null,
            'progress' => $budget ? $this->progress($budget) : [],
            'measurements' => $measurements->map(fn (Measurement $m) => $this->present($m))->all(),
        ]]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('measurements.manage', $project);
        $this->assertProjectOpen($project);
        $budget = $this->approvedBudget($project);
        $data = $this->validated($request, $project, $budget);

        $measurement = $project->measurements()->make($data);
        $measurement->created_by_user_id = $request->user()->id;
        $measurement->save();
        $this->auditor->record('measurement.created', $measurement, ['project' => $project->code, 'item' => $measurement->item->code, 'quantity' => (float) $measurement->quantity]);

        return response()->json(['data' => $this->present($measurement->load(['item:id,code,description,unit', 'createdBy:id,name']))], 201);
    }

    public function update(Request $request, Project $project, Measurement $measurement): JsonResponse
    {
        $this->authorizeEdit($project, $measurement);
        $measurement->fill($this->validated($request, $project, $this->approvedBudget($project), $measurement))->save();

        return response()->json(['data' => $this->present($measurement->load(['item:id,code,description,unit', 'createdBy:id,name']))]);
    }

    public function destroy(Project $project, Measurement $measurement): Response
    {
        $this->authorizeEdit($project, $measurement);
        $measurement->delete();

        return response()->noContent();
    }

    private function authorizeEdit(Project $project, Measurement $measurement): void
    {
        $this->authorize('measurements.manage', $project);
        $this->assertProjectOpen($project);
        if ($measurement->certificate_id !== null) {
            throw ApiException::conflict("Esta medição já está no auto n.º {$measurement->certificate?->number}.", 'MEASUREMENT_CERTIFIED');
        }
    }

    private function approvedBudget(Project $project, bool $required = true): ?Budget
    {
        $budget = $project->budgets()->where('status', BudgetStatus::Approved->value)->latest('approved_at')->first();
        if ($budget === null && $required) {
            throw ApiException::conflict('A obra ainda não tem orçamento aprovado: as medições são sobre os artigos dele.', 'NO_APPROVED_BUDGET');
        }

        return $budget;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, Budget $budget, ?Measurement $measurement = null): array
    {
        $today = now('Europe/Lisbon')->toDateString();
        $dim = ['nullable', 'numeric', 'min:0', 'max:9999999'];
        $data = $request->validate([
            'budget_item_id' => [$measurement ? 'sometimes' : 'required', 'integer', Rule::exists('budget_items', 'id')->where('budget_id', $budget->id)],
            'measured_on' => [$measurement ? 'sometimes' : 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'count' => $dim, 'length' => $dim, 'width' => $dim, 'height' => $dim,
            'quantity' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
        ] + Place::rules($project), [
            'budget_item_id.exists' => 'Escolha um artigo do orçamento aprovado.',
            'measured_on.before_or_equal' => 'Não se mede o que ainda não foi feito.',
        ], ['budget_item_id' => 'artigo', 'measured_on' => 'data', 'quantity' => 'quantidade']);

        // Quantidade: a escrita, ou o produto das dimensões preenchidas.
        $dims = array_filter(array_map(fn ($k) => self::decimal($data[$k] ?? null), ['count' => 'count', 'length' => 'length', 'width' => 'width', 'height' => 'height']), fn ($v) => $v !== null);
        $quantity = self::decimal($data['quantity'] ?? null);
        if ($quantity === null && $dims !== []) {
            $quantity = round(array_product($dims), 3);
        }
        if ($quantity === null && $measurement === null) {
            throw ValidationException::withMessages(['quantity' => 'Escreva a quantidade ou as dimensões.']);
        }
        if ($quantity !== null) {
            $data['quantity'] = $quantity;
        }
        foreach (['count', 'length', 'width', 'height'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = self::decimal($data[$k]);
            }
        }
        if (array_key_exists('location_id', $data) || array_key_exists('element_id', $data)) {
            $data = array_merge($data, Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]));
        }

        return $data;
    }

    /** Por artigo do orçamento aprovado: orçado, medido e já em autos aprovados. @return list<array<string, mixed>> */
    private function progress(Budget $budget): array
    {
        $items = $budget->items()->orderBy('sort_order')->orderBy('id')->get();
        $measured = Measurement::query()->whereIn('budget_item_id', $items->pluck('id'))->groupBy('budget_item_id')
            ->selectRaw('budget_item_id, sum(quantity) as q')->pluck('q', 'budget_item_id');
        $certified = CertificateLine::query()->whereIn('budget_item_id', $items->pluck('id'))
            ->whereHas('certificate', fn ($q) => $q->where('status', CertificateStatus::Approved->value))
            ->groupBy('budget_item_id')->selectRaw('budget_item_id, sum(qty_period) as q')->pluck('q', 'budget_item_id');

        return $items->map(fn (BudgetItem $i) => [
            'budget_item_id' => $i->id,
            'code' => $i->code,
            'description' => $i->description,
            'unit' => $i->unit,
            'budget_quantity' => (float) $i->quantity,
            'measured_quantity' => (float) ($measured[$i->id] ?? 0),
            'certified_quantity' => (float) ($certified[$i->id] ?? 0),
            'measured_pct' => (float) $i->quantity > 0 ? round((float) ($measured[$i->id] ?? 0) * 100 / (float) $i->quantity, 1) : null,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function present(Measurement $m): array
    {
        return [
            'id' => $m->id,
            'budget_item_id' => $m->budget_item_id,
            'item' => $m->item ? ['code' => $m->item->code, 'description' => $m->item->description, 'unit' => $m->item->unit] : null,
            'measured_on' => $m->measured_on->toDateString(),
            'description' => $m->description,
            'count' => $m->count === null ? null : (float) $m->count,
            'length' => $m->length === null ? null : (float) $m->length,
            'width' => $m->width === null ? null : (float) $m->width,
            'height' => $m->height === null ? null : (float) $m->height,
            'quantity' => (float) $m->quantity,
            'location_id' => $m->location_id,
            'element_id' => $m->element_id,
            'certificate' => $m->certificate ? ['id' => $m->certificate->id, 'number' => $m->certificate->number, 'status' => $m->certificate->status->value] : null,
            'created_by' => $m->createdBy?->name,
        ];
    }
}
