<?php

namespace App\Economics;

use App\Enums\CostCategory;
use App\Enums\ResourceKind;
use App\Models\Budget;
use App\Models\BudgetChapter;
use App\Models\BudgetItem;
use App\Models\BudgetItemComponent;
use Illuminate\Support\Collection;

/**
 * Contas de um orçamento, em cêntimos: custo e venda por artigo, por capítulo (com subcapítulos)
 * e do orçamento; IVA; e o custo previsto por natureza (mão de obra, materiais...), que é o que o
 * mapa de custos compara com o realizado.
 */
final class BudgetCalculator
{
    /**
     * @return array{
     *     cost_cents: int, sale_cents: int, vat_cents: int, total_cents: int, margin_pct: float|null,
     *     by_category: array<string, int>, chapters: array<int, array{cost_cents: int, sale_cents: int}>,
     *     items: array<int, array{unit_price_cents: int, cost_cents: int, sale_cents: int}>
     * }
     */
    public static function totals(Budget $budget): array
    {
        $budget->loadMissing(['chapters', 'items.components']);

        $items = [];
        $byChapter = [];
        $byCategory = array_fill_keys(array_map(fn (CostCategory $c) => $c->value, CostCategory::cases()), 0);

        foreach ($budget->items as $item) {
            $quantity = (float) $item->quantity;
            $unitPrice = $item->salePriceCents($budget);
            $cost = (int) round($quantity * $item->unit_cost_cents);
            $sale = (int) round($quantity * $unitPrice);
            $items[$item->id] = ['unit_price_cents' => $unitPrice, 'cost_cents' => $cost, 'sale_cents' => $sale];

            $byChapter[$item->chapter_id] ??= ['cost_cents' => 0, 'sale_cents' => 0];
            $byChapter[$item->chapter_id]['cost_cents'] += $cost;
            $byChapter[$item->chapter_id]['sale_cents'] += $sale;

            foreach (self::costByCategory($item) as $category => $unitCost) {
                $byCategory[$category] += (int) round($quantity * $unitCost);
            }
        }

        // Subcapítulos somam no capítulo de cima (do mais fundo para cima).
        $chapters = $budget->chapters->keyBy('id');
        $depth = fn (BudgetChapter $c) => self::depth($c, $chapters);
        $rolled = [];
        foreach ($chapters as $id => $chapter) {
            $rolled[$id] = $byChapter[$id] ?? ['cost_cents' => 0, 'sale_cents' => 0];
        }
        foreach ($chapters->sortByDesc($depth) as $chapter) {
            if ($chapter->parent_id !== null && isset($rolled[$chapter->parent_id])) {
                $rolled[$chapter->parent_id]['cost_cents'] += $rolled[$chapter->id]['cost_cents'];
                $rolled[$chapter->parent_id]['sale_cents'] += $rolled[$chapter->id]['sale_cents'];
            }
        }

        $cost = array_sum(array_column($items, 'cost_cents'));
        $sale = array_sum(array_column($items, 'sale_cents'));
        $vat = (int) round($sale * $budget->effectiveVatRate() / 100);

        return [
            'cost_cents' => $cost,
            'sale_cents' => $sale,
            'vat_cents' => $vat,
            'total_cents' => $sale + $vat,
            'margin_pct' => $sale > 0 ? round(($sale - $cost) * 100 / $sale, 2) : null,
            'by_category' => $byCategory,
            'chapters' => $rolled,
            'items' => $items,
        ];
    }

    /**
     * Custo unitário de um artigo repartido por natureza. Sem composição, o custo todo conta como
     * "outros" (não se sabe de que é feito).
     *
     * @return array<string, float>
     */
    public static function costByCategory(BudgetItem $item): array
    {
        $components = $item->relationLoaded('components') ? $item->components : $item->components()->get();
        if ($components->isEmpty()) {
            return [CostCategory::Other->value => (float) $item->unit_cost_cents];
        }

        $out = [];
        foreach ($components as $component) {
            /** @var BudgetItemComponent $component */
            $category = self::categoryOf($component->kind)->value;
            $out[$category] = ($out[$category] ?? 0) + (float) $component->quantity * $component->unit_cost_cents;
        }

        return $out;
    }

    public static function categoryOf(ResourceKind $kind): CostCategory
    {
        return match ($kind) {
            ResourceKind::Labor => CostCategory::Labor,
            ResourceKind::Material => CostCategory::Material,
            ResourceKind::Equipment => CostCategory::Equipment,
            ResourceKind::Subcontract => CostCategory::Subcontract,
        };
    }

    /** Custo unitário da composição de um artigo do orçamento. */
    public static function unitCostOf(iterable $components): int
    {
        $total = 0.0;
        foreach ($components as $c) {
            $total += (float) $c['quantity'] * (int) $c['unit_cost_cents'];
        }

        return (int) round($total);
    }

    /** @param  Collection<int, BudgetChapter>  $chapters */
    private static function depth(BudgetChapter $chapter, $chapters): int
    {
        $depth = 0;
        $current = $chapter;
        while ($current->parent_id !== null && $chapters->has($current->parent_id) && $depth < 10) {
            $current = $chapters[$current->parent_id];
            $depth++;
        }

        return $depth;
    }
}
