<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Economics\BudgetCalculator;
use App\Economics\BudgetImporter;
use App\Enums\BudgetStatus;
use App\Enums\ResourceKind;
use App\Enums\VatMode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetChapter;
use App\Models\BudgetItem;
use App\Models\BudgetItemComponent;
use App\Models\Measurement;
use App\Models\PriceResource;
use App\Models\Project;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orçamentos de uma obra: capítulos, artigos com composição de preço, encargos e margem, IVA.
 * Só o rascunho se altera; o aprovado é a base do contrato, dos autos e do custo previsto, e muda-se
 * com uma revisão (nova versão). Consultar: budgets.view ou costs.view; alterar: budgets.manage.
 */
class BudgetController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorizeView($project);

        return response()->json(['data' => $project->budgets()->with(['chapters', 'items.components'])->orderByDesc('id')->get()
            ->map(fn (Budget $b) => $this->summary($b))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('budgets.manage', $project);
        $this->assertProjectOpen($project);
        $data = $this->validatedHeader($request);

        $budget = $project->budgets()->make($data + ['name' => $data['name'] ?? 'Orçamento']);
        $budget->code = $data['code'] ?? 'ORC-'.str_pad((string) ($project->budgets()->distinct('code')->count('code') + 1), 2, '0', STR_PAD_LEFT);
        $budget->created_by_user_id = $request->user()->id;
        $budget->save();
        $this->auditor->record('budget.created', $budget, ['project' => $project->code, 'code' => $budget->code]);

        return response()->json(['data' => $this->present($budget)], 201);
    }

    public function show(Project $project, Budget $budget): JsonResponse
    {
        $this->authorizeView($project);

        return response()->json(['data' => $this->present($budget)]);
    }

    public function update(Request $request, Project $project, Budget $budget): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $budget->fill($this->validatedHeader($request, $budget))->save();

        return response()->json(['data' => $this->present($budget)]);
    }

    public function destroy(Project $project, Budget $budget): Response
    {
        $this->authorizeEdit($project, $budget);
        if ($project->certificates()->where('budget_id', $budget->id)->exists()) {
            throw ApiException::conflict('Há autos sobre este orçamento.', 'BUDGET_IN_USE');
        }
        $budget->delete();
        $this->auditor->record('budget.deleted', $project, ['code' => $budget->code, 'version' => $budget->version]);

        return response()->noContent();
    }

    /** Aprova: passa a ser a base da obra; o aprovado anterior fica "substituído". */
    public function approve(Request $request, Project $project, Budget $budget): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        if (! $budget->items()->exists()) {
            throw ApiException::unprocessable('O orçamento não tem artigos.', 'BUDGET_EMPTY');
        }

        DB::transaction(function () use ($project, $budget, $request): void {
            $project->budgets()->where('status', BudgetStatus::Approved->value)->whereKeyNot($budget->id)
                ->update(['status' => BudgetStatus::Superseded->value]);
            $budget->forceFill(['status' => BudgetStatus::Approved, 'approved_by_user_id' => $request->user()->id, 'approved_at' => now()])->save();
        });
        $totals = BudgetCalculator::totals($budget->refresh());
        $this->auditor->record('budget.approved', $budget, ['project' => $project->code, 'code' => $budget->code, 'version' => $budget->version, 'sale_cents' => $totals['sale_cents']]);

        return response()->json(['data' => $this->present($budget)]);
    }

    /** Nova versão em rascunho, cópia do aprovado (capítulos, artigos e composições). */
    public function revise(Request $request, Project $project, Budget $budget): JsonResponse
    {
        $this->authorize('budgets.manage', $project);
        $this->assertProjectOpen($project);
        if ($budget->status !== BudgetStatus::Approved) {
            throw ApiException::conflict('Só se revê o orçamento aprovado.', 'BUDGET_STATUS');
        }
        if ($project->budgets()->where('revision_of_id', $budget->id)->where('status', BudgetStatus::Draft->value)->exists()) {
            throw ApiException::conflict('Já há uma revisão em rascunho deste orçamento.', 'REVISION_EXISTS');
        }

        $copy = DB::transaction(function () use ($project, $budget, $request): Budget {
            $budget->load(['chapters', 'items.components']);
            $copy = $project->budgets()->make($budget->only(['code', 'name', 'vat_mode', 'vat_rate', 'overhead_pct', 'profit_pct', 'notes']));
            $copy->version = (int) $project->budgets()->where('code', $budget->code)->max('version') + 1;
            $copy->revision_of_id = $budget->id;
            $copy->created_by_user_id = $request->user()->id;
            $copy->save();

            // Pais antes dos filhos: cada volta copia os capítulos cujo pai já foi copiado.
            $map = [];
            $pending = $budget->chapters->all();
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $k => $chapter) {
                    if ($chapter->parent_id !== null && ! isset($map[$chapter->parent_id])) {
                        continue;
                    }
                    $map[$chapter->id] = $copy->chapters()->create([
                        'parent_id' => $chapter->parent_id ? $map[$chapter->parent_id] : null,
                        'code' => $chapter->code, 'name' => $chapter->name, 'sort_order' => $chapter->sort_order,
                    ])->id;
                    unset($pending[$k]);
                    $progress = true;
                }
                if (! $progress) {
                    break;
                }
            }
            foreach ($budget->items as $item) {
                $new = $copy->items()->create(['chapter_id' => $map[$item->chapter_id]] + $item->only(['article_id', 'specialty_id', 'code', 'description', 'unit', 'quantity', 'unit_cost_cents', 'unit_price_cents', 'sort_order']));
                foreach ($item->components as $c) {
                    $new->components()->create($c->only(['resource_id', 'kind', 'name', 'unit', 'quantity', 'unit_cost_cents', 'sort_order']));
                }
            }

            return $copy;
        });
        $this->auditor->record('budget.revised', $copy, ['project' => $project->code, 'code' => $copy->code, 'version' => $copy->version]);

        return response()->json(['data' => $this->present($copy)], 201);
    }

    public function storeChapter(Request $request, Project $project, Budget $budget): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:30'],
            'parent_id' => ['nullable', 'integer', Rule::exists('budget_chapters', 'id')->where('budget_id', $budget->id)],
        ], [], ['name' => 'nome', 'code' => 'código']);

        $parent = isset($data['parent_id']) ? $budget->chapters()->find($data['parent_id']) : null;
        $siblings = $budget->chapters()->where('parent_id', $parent?->id)->count();
        $budget->chapters()->create([
            'parent_id' => $parent?->id,
            'code' => $data['code'] ?? ($parent ? "{$parent->code}." : '').($siblings + 1),
            'name' => $data['name'],
            'sort_order' => (int) $budget->chapters()->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $this->present($budget)], 201);
    }

    public function updateChapter(Request $request, Project $project, Budget $budget, BudgetChapter $chapter): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $chapter->update($request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'code' => ['sometimes', 'string', 'max:30'],
        ], [], ['name' => 'nome', 'code' => 'código']));

        return response()->json(['data' => $this->present($budget)]);
    }

    public function destroyChapter(Project $project, Budget $budget, BudgetChapter $chapter): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $chapter->delete();

        return response()->json(['data' => $this->present($budget)]);
    }

    public function storeItem(Request $request, Project $project, Budget $budget): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $data = $this->validatedItem($request, $budget);

        DB::transaction(function () use ($budget, $data): void {
            $chapter = $budget->chapters()->findOrFail($data['chapter_id']);
            $article = isset($data['article_id']) ? Article::query()->with('components.resource')->find($data['article_id']) : null;
            $components = $data['components'] ?? ($article ? $article->components->map(fn ($c) => [
                'resource_id' => $c->resource_id, 'quantity' => (float) $c->quantity,
            ])->all() : null);

            $item = $budget->items()->create([
                'chapter_id' => $chapter->id,
                'article_id' => $article?->id,
                'specialty_id' => $data['specialty_id'] ?? null,
                'code' => $data['code'] ?? $chapter->code.'.'.($chapter->items()->count() + 1),
                'description' => $data['description'] ?? $article?->description ?? '',
                'unit' => $data['unit'] ?? $article?->unit ?? 'un',
                'quantity' => self::decimal($data['quantity'] ?? 0) ?? 0,
                'unit_cost_cents' => $data['unit_cost_cents'] ?? $article?->unitCostCents() ?? 0,
                'unit_price_cents' => $data['unit_price_cents'] ?? null,
                'sort_order' => (int) $budget->items()->max('sort_order') + 1,
            ]);
            if ($components !== null) {
                $this->syncComponents($item, $components);
            }
        });

        return response()->json(['data' => $this->present($budget)], 201);
    }

    public function updateItem(Request $request, Project $project, Budget $budget, BudgetItem $item): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $data = $this->validatedItem($request, $budget, $item);

        DB::transaction(function () use ($item, $data): void {
            $fields = collect($data)->only(['chapter_id', 'specialty_id', 'code', 'description', 'unit', 'unit_cost_cents', 'unit_price_cents'])->all();
            if (array_key_exists('quantity', $data)) {
                $fields['quantity'] = self::decimal($data['quantity']) ?? 0;
            }
            $item->fill($fields)->save();
            if (array_key_exists('components', $data)) {
                $this->syncComponents($item, $data['components'] ?? []);
            }
        });

        return response()->json(['data' => $this->present($budget)]);
    }

    public function destroyItem(Project $project, Budget $budget, BudgetItem $item): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $item->delete();

        return response()->json(['data' => $this->present($budget)]);
    }

    public function import(Request $request, Project $project, Budget $budget, BudgetImporter $importer): JsonResponse
    {
        $this->authorizeEdit($project, $budget);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,csv,txt']], [
            'file.extensions' => 'Envie uma folha Excel (.xlsx) ou um ficheiro CSV.',
        ], ['file' => 'ficheiro']);

        $file = $request->file('file');
        $counts = DB::transaction(fn () => $importer->import($budget, $file->getRealPath(), strtolower($file->getClientOriginalExtension())));
        $this->auditor->record('budget.imported', $budget, ['project' => $project->code, 'code' => $budget->code] + $counts);

        return response()->json(['data' => $this->present($budget), 'meta' => ['imported' => $counts]]);
    }

    /** CSV (;) com capítulos e artigos, para abrir no Excel. */
    public function export(Project $project, Budget $budget): StreamedResponse
    {
        abort_unless(Gate::any(['budgets.view', 'budgets.manage', 'reports.export'], $project), 403);
        $totals = BudgetCalculator::totals($budget);
        $budget->loadMissing(['chapters', 'items']);
        $filename = "orcamento-{$project->code}-{$budget->code}-v{$budget->version}.csv";

        return response()->streamDownload(function () use ($budget, $totals): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Código', 'Descrição', 'Unidade', 'Quantidade', 'Custo unitário', 'Preço unitário', 'Total'], ';');
            $money = fn (int $cents) => number_format($cents / 100, 2, ',', '');
            foreach ($this->orderedChapters($budget) as $chapter) {
                fputcsv($out, [$chapter->code, $chapter->name, '', '', '', '', $money($totals['chapters'][$chapter->id]['sale_cents'] ?? 0)], ';');
                foreach ($budget->items->where('chapter_id', $chapter->id) as $item) {
                    $t = $totals['items'][$item->id];
                    fputcsv($out, [$item->code, $item->description, $item->unit, number_format((float) $item->quantity, 3, ',', ''), $money($item->unit_cost_cents), $money($t['unit_price_cents']), $money($t['sale_cents'])], ';');
                }
            }
            fputcsv($out, ['', 'Total sem IVA', '', '', '', '', $money($totals['sale_cents'])], ';');
            fputcsv($out, ['', 'IVA', '', '', '', '', $money($totals['vat_cents'])], ';');
            fputcsv($out, ['', 'Total com IVA', '', '', '', '', $money($totals['total_cents'])], ';');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeView(Project $project): void
    {
        $this->authorizeAny(['budgets.view', 'budgets.manage', 'costs.view', 'measurements.manage', 'certificates.approve'], $project);
    }

    private function authorizeEdit(Project $project, Budget $budget): void
    {
        $this->authorize('budgets.manage', $project);
        $this->assertProjectOpen($project);
        if (! $budget->isEditable()) {
            throw ApiException::conflict('O orçamento está «'.$budget->status->label().'»: para o mudar, faça uma revisão.', 'BUDGET_STATUS');
        }
    }

    /** @param  list<array<string, mixed>>  $components */
    private function syncComponents(BudgetItem $item, array $components): void
    {
        $item->components()->delete();
        $resources = PriceResource::query()->whereKey(array_filter(array_column($components, 'resource_id')))->get()->keyBy('id');
        $rows = [];
        foreach (array_values($components) as $i => $c) {
            $resource = isset($c['resource_id']) ? $resources[$c['resource_id']] ?? null : null;
            $row = [
                'resource_id' => $resource?->id,
                'kind' => $resource?->kind->value ?? ($c['kind'] ?? ResourceKind::Material->value),
                'name' => $resource?->name ?? ($c['name'] ?? '—'),
                'unit' => $resource?->unit ?? ($c['unit'] ?? 'un'),
                'quantity' => self::decimal($c['quantity']) ?? 0,
                'unit_cost_cents' => isset($c['unit_cost_cents']) ? (int) $c['unit_cost_cents'] : (int) ($resource?->unit_cost_cents ?? 0),
                'sort_order' => $i,
            ];
            $item->components()->create($row);
            $rows[] = $row;
        }
        // Com composição, o custo unitário é a soma; sem ela, fica o que estava escrito.
        if ($rows !== []) {
            $item->forceFill(['unit_cost_cents' => BudgetCalculator::unitCostOf($rows)])->save();
        }
    }

    /** @return array<string, mixed> */
    private function validatedHeader(Request $request, ?Budget $budget = null): array
    {
        return $request->validate([
            'code' => [$budget ? 'prohibited' : 'nullable', 'string', 'max:30'],
            'name' => [$budget ? 'sometimes' : 'nullable', 'string', 'max:200'],
            'vat_mode' => ['sometimes', Rule::enum(VatMode::class)],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'overhead_pct' => ['sometimes', 'numeric', 'between:0,500'],
            'profit_pct' => ['sometimes', 'numeric', 'between:-100,500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], ['vat_rate' => 'taxa de IVA', 'overhead_pct' => 'encargos', 'profit_pct' => 'margem', 'name' => 'nome']);
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request, Budget $budget, ?BudgetItem $item = null): array
    {
        $tenant = $this->tenantContext()->id();
        $data = $request->validate([
            'chapter_id' => [$item ? 'sometimes' : 'required', 'integer', Rule::exists('budget_chapters', 'id')->where('budget_id', $budget->id)],
            'article_id' => [$item ? 'prohibited' : 'nullable', 'integer', Rule::exists('articles', 'id')->where('tenant_id', $tenant)->whereNull('archived_at')],
            'specialty_id' => Specialty::rules($item?->specialty_id),
            'code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'description' => [$item ? 'sometimes' : 'required_without:article_id', 'string', 'max:1000'],
            'unit' => [$item ? 'sometimes' : 'required_without:article_id', 'string', 'max:20'],
            'quantity' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'unit_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'components' => ['sometimes', 'nullable', 'array', 'max:50'],
            'components.*.resource_id' => ['nullable', 'integer', Rule::exists('resources', 'id')->where('tenant_id', $tenant)],
            'components.*.kind' => ['required_without:components.*.resource_id', Rule::enum(ResourceKind::class)],
            'components.*.name' => ['required_without:components.*.resource_id', 'string', 'max:200'],
            'components.*.unit' => ['nullable', 'string', 'max:20'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'components.*.unit_cost_cents' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'chapter_id' => 'capítulo', 'article_id' => 'artigo', 'description' => 'descrição', 'unit' => 'unidade', 'quantity' => 'quantidade',
            'unit_cost_cents' => 'custo unitário', 'unit_price_cents' => 'preço unitário', 'components.*.quantity' => 'rendimento',
        ]);
        if (array_key_exists('code', $data) && blank($data['code'])) {
            unset($data['code']);
        }

        return $data;
    }

    /** @return Collection<int, BudgetChapter> capítulos pela ordem da árvore */
    private function orderedChapters(Budget $budget)
    {
        $byParent = $budget->chapters->groupBy(fn (BudgetChapter $c) => $c->parent_id ?? 0);
        $out = collect();
        $walk = function (int $parent) use (&$walk, $byParent, $out): void {
            foreach (($byParent[$parent] ?? collect())->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $chapter) {
                $out->push($chapter);
                $walk($chapter->id);
            }
        };
        $walk(0);

        return $out;
    }

    /** @return array<string, mixed> */
    private function summary(Budget $budget): array
    {
        $totals = BudgetCalculator::totals($budget);

        return [
            'id' => $budget->id,
            'code' => $budget->code,
            'name' => $budget->name,
            'version' => $budget->version,
            'status' => $budget->status->value,
            'status_label' => $budget->status->label(),
            'revision_of_id' => $budget->revision_of_id,
            'approved_at' => $budget->approved_at?->toIso8601String(),
            'items_count' => $budget->items->count(),
            'cost_cents' => $totals['cost_cents'],
            'sale_cents' => $totals['sale_cents'],
            'total_cents' => $totals['total_cents'],
            'margin_pct' => $totals['margin_pct'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Budget $budget): array
    {
        $budget->unsetRelation('chapters')->unsetRelation('items')->load(['chapters', 'items.components', 'approvedBy:id,name', 'createdBy:id,name']);
        $totals = BudgetCalculator::totals($budget);
        $project = $budget->project;
        $manage = Gate::allows('budgets.manage', $project) && ! $project->isArchived();
        $measured = $budget->status === BudgetStatus::Approved
            ? Measurement::query()->whereIn('budget_item_id', $budget->items->pluck('id'))->groupBy('budget_item_id')->selectRaw('budget_item_id, sum(quantity) as q')->pluck('q', 'budget_item_id')
            : collect();

        return $this->summary($budget) + [
            'vat_mode' => $budget->vat_mode->value,
            'vat_mode_label' => $budget->vat_mode->label(),
            'vat_rate' => (float) $budget->vat_rate,
            'overhead_pct' => (float) $budget->overhead_pct,
            'profit_pct' => (float) $budget->profit_pct,
            'notes' => $budget->notes,
            'approved_by' => $budget->approvedBy?->name,
            'created_by' => $budget->createdBy?->name,
            'vat_cents' => $totals['vat_cents'],
            'by_category' => $totals['by_category'],
            'chapters' => $this->orderedChapters($budget)->map(fn (BudgetChapter $c) => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'code' => $c->code,
                'name' => $c->name,
                'cost_cents' => $totals['chapters'][$c->id]['cost_cents'] ?? 0,
                'sale_cents' => $totals['chapters'][$c->id]['sale_cents'] ?? 0,
            ])->values()->all(),
            'items' => $budget->items->map(fn (BudgetItem $i) => [
                'id' => $i->id,
                'chapter_id' => $i->chapter_id,
                'article_id' => $i->article_id,
                'specialty_id' => $i->specialty_id,
                'code' => $i->code,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'unit_cost_cents' => $i->unit_cost_cents,
                'unit_price_cents' => $i->unit_price_cents,
                'sale_unit_cents' => $totals['items'][$i->id]['unit_price_cents'],
                'cost_cents' => $totals['items'][$i->id]['cost_cents'],
                'sale_cents' => $totals['items'][$i->id]['sale_cents'],
                'measured_quantity' => isset($measured[$i->id]) ? (float) $measured[$i->id] : null,
                'components' => $i->components->map(fn (BudgetItemComponent $c) => [
                    'resource_id' => $c->resource_id,
                    'kind' => $c->kind->value,
                    'kind_label' => $c->kind->label(),
                    'name' => $c->name,
                    'unit' => $c->unit,
                    'quantity' => (float) $c->quantity,
                    'unit_cost_cents' => $c->unit_cost_cents,
                ])->all(),
            ])->all(),
            'can' => [
                'edit' => $manage && $budget->isEditable(),
                'approve' => $manage && $budget->isEditable(),
                'revise' => $manage && $budget->status === BudgetStatus::Approved
                    && ! $budget->project->budgets()->where('revision_of_id', $budget->id)->where('status', BudgetStatus::Draft->value)->exists(),
            ],
        ];
    }
}
