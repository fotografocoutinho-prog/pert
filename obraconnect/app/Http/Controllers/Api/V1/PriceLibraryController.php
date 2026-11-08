<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\ResourceKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleComponent;
use App\Models\BudgetItem;
use App\Models\PriceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Biblioteca de preços da empresa: recursos (com custo unitário) e artigos (simples ou compostos).
 * Consultar: budgets.view; alterar: budgets.manage. Usados não se apagam: arquivam-se.
 */
class PriceLibraryController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        $this->authorizeAny(['budgets.view', 'budgets.manage']);

        $resources = PriceResource::query()->withCount('components')->orderBy('kind')->orderBy('code')->get();
        $articles = Article::query()->with('components.resource')->orderBy('code')->get();

        return response()->json(['data' => [
            'kinds' => ResourceKind::options(),
            'resources' => $resources->map(fn (PriceResource $r) => $this->presentResource($r)),
            'articles' => $articles->map(fn (Article $a) => $this->presentArticle($a)),
        ]]);
    }

    public function storeResource(Request $request): JsonResponse
    {
        $this->authorize('budgets.manage');
        $resource = PriceResource::query()->create($this->validatedResource($request));
        $this->auditor->record('price.resource_created', $resource, ['code' => $resource->code]);

        return response()->json(['data' => $this->presentResource($resource->loadCount('components'))], 201);
    }

    public function updateResource(Request $request, PriceResource $priceResource): JsonResponse
    {
        $this->authorize('budgets.manage');
        $resource = $priceResource;
        $data = $this->validatedResource($request, $resource);
        if (array_key_exists('archived', $data)) {
            $resource->archived_at = $data['archived'] ? ($resource->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $resource->fill($data)->save();

        return response()->json(['data' => $this->presentResource($resource->loadCount('components'))]);
    }

    public function destroyResource(PriceResource $priceResource): Response
    {
        $this->authorize('budgets.manage');
        $resource = $priceResource;
        if ($resource->components()->exists()) {
            throw ApiException::conflict('Este recurso entra na composição de artigos. Arquive-o em vez de o apagar.', 'RESOURCE_IN_USE');
        }
        $resource->delete();

        return response()->noContent();
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $this->authorize('budgets.manage');
        $data = $this->validatedArticle($request);
        $article = DB::transaction(function () use ($data): Article {
            $article = Article::query()->create(collect($data)->except('components')->all());
            $this->syncComponents($article, $data['components'] ?? []);

            return $article;
        });
        $this->auditor->record('price.article_created', $article, ['code' => $article->code]);

        return response()->json(['data' => $this->presentArticle($article->load('components.resource'))], 201);
    }

    public function updateArticle(Request $request, Article $article): JsonResponse
    {
        $this->authorize('budgets.manage');
        $data = $this->validatedArticle($request, $article);
        DB::transaction(function () use ($article, $data): void {
            if (array_key_exists('archived', $data)) {
                $article->archived_at = $data['archived'] ? ($article->archived_at ?? now()) : null;
            }
            $article->fill(collect($data)->except(['components', 'archived'])->all())->save();
            if (array_key_exists('components', $data)) {
                $this->syncComponents($article, $data['components'] ?? []);
            }
        });

        return response()->json(['data' => $this->presentArticle($article->load('components.resource'))]);
    }

    public function destroyArticle(Article $article): Response
    {
        $this->authorize('budgets.manage');
        if (BudgetItem::query()->where('article_id', $article->id)->exists()) {
            throw ApiException::conflict('Este artigo foi usado em orçamentos. Arquive-o em vez de o apagar.', 'ARTICLE_IN_USE');
        }
        $article->delete();

        return response()->noContent();
    }

    /** @param  list<array{resource_id: int, quantity: mixed}>  $components */
    private function syncComponents(Article $article, array $components): void
    {
        $article->components()->delete();
        foreach (array_values($components) as $i => $c) {
            $article->components()->create(['resource_id' => $c['resource_id'], 'quantity' => self::decimal($c['quantity']), 'sort_order' => $i]);
        }
    }

    /** @return array<string, mixed> */
    private function validatedResource(Request $request, ?PriceResource $resource = null): array
    {
        $required = $resource ? 'sometimes' : 'required';

        return $request->validate([
            'kind' => [$required, Rule::enum(ResourceKind::class)],
            'code' => [$required, 'string', 'max:30', Rule::unique('resources', 'code')->where('tenant_id', $this->tenantContext()->id())->ignore($resource?->id)],
            'name' => [$required, 'string', 'max:200'],
            'unit' => [$required, 'string', 'max:20'],
            'unit_cost_cents' => [$required, 'integer', 'min:0', 'max:100000000000'],
            'archived' => $resource ? ['sometimes', 'boolean'] : ['prohibited'],
        ], [], ['kind' => 'tipo', 'code' => 'código', 'name' => 'nome', 'unit' => 'unidade', 'unit_cost_cents' => 'custo unitário']);
    }

    /** @return array<string, mixed> */
    private function validatedArticle(Request $request, ?Article $article = null): array
    {
        $required = $article ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:30', Rule::unique('articles', 'code')->where('tenant_id', $this->tenantContext()->id())->ignore($article?->id)],
            'description' => [$required, 'string', 'max:1000'],
            'unit' => [$required, 'string', 'max:20'],
            'unit_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'archived' => $article ? ['sometimes', 'boolean'] : ['prohibited'],
            'components' => ['sometimes', 'array', 'max:50'],
            'components.*.resource_id' => ['required', 'integer', Rule::exists('resources', 'id')->where('tenant_id', $this->tenantContext()->id())],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [], ['code' => 'código', 'description' => 'descrição', 'unit' => 'unidade', 'components.*.quantity' => 'rendimento']);
    }

    /** @return array<string, mixed> */
    private function presentResource(PriceResource $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'code' => $r->code,
            'name' => $r->name,
            'unit' => $r->unit,
            'unit_cost_cents' => $r->unit_cost_cents,
            'archived' => $r->archived_at !== null,
            'used' => (int) ($r->components_count ?? 0) > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function presentArticle(Article $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'description' => $a->description,
            'unit' => $a->unit,
            'composed' => $a->components->isNotEmpty(),
            'unit_cost_cents' => $a->unitCostCents(),
            'notes' => $a->notes,
            'archived' => $a->archived_at !== null,
            'components' => $a->components->map(fn (ArticleComponent $c) => [
                'resource_id' => $c->resource_id,
                'code' => $c->resource->code,
                'name' => $c->resource->name,
                'kind' => $c->resource->kind->value,
                'unit' => $c->resource->unit,
                'quantity' => (float) $c->quantity,
                'unit_cost_cents' => $c->resource->unit_cost_cents,
            ])->all(),
        ];
    }
}
