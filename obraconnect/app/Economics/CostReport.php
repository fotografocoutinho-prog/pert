<?php

namespace App\Economics;

use App\Enums\BudgetStatus;
use App\Enums\CertificateStatus;
use App\Enums\ContractStatus;
use App\Enums\CostCategory;
use App\Enums\PurchaseStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\Contract;
use App\Models\CostEntry;
use App\Models\EquipmentUsage;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;

/**
 * Mapa de custos de uma obra, calculado no momento a partir das origens (nada é copiado):
 *
 * - previsto: custo do orçamento aprovado, por natureza;
 * - comprometido: compras aprovadas ou recebidas ainda por faturar, o que falta autar dos contratos
 *   de subempreitada em curso e os comprometidos lançados à mão;
 * - realizado: compras faturadas (valor da fatura), horas aprovadas, horas de equipamento, autos de
 *   subempreitada aprovados e os realizados lançados à mão.
 *
 * O teto é o da obra (se escrito) ou o custo previsto. "forecast_cents" = realizado + comprometido
 * (o que já se sabe que a obra custa); a previsão do custo no fim, com o que falta fazer, é a de
 * App\Forecasting\CostForecast.
 */
final class CostReport
{
    /** A partir daqui (% do teto) a previsão final fica em alerta. */
    public const WARNING_PCT = 90;

    /**
     * Cada custo da obra, com a data e a natureza: a base do mapa e da previsão (que precisa de saber
     * o realizado até à data da última medição).
     *
     * "received_on" só nas compras recebidas por faturar: o material já está em obra (custo incorrido),
     * mesmo sem fatura — a previsão conta-o como gasto ao comparar com o trabalho medido.
     *
     * @return list<array{kind: 'actual'|'committed', category: string, cents: int, date: string|null, center_id: int|null, received_on?: string|null}>
     */
    public static function movements(Project $project): array
    {
        $rows = [];
        $add = function (string $kind, string $category, int $cents, ?string $date, ?int $center, array $extra = []) use (&$rows): void {
            $rows[] = ['kind' => $kind, 'category' => $category, 'cents' => $cents, 'date' => $date, 'center_id' => $center] + $extra;
        };

        foreach ($project->purchaseOrders()->with('lines')->get() as $order) {
            /** @var PurchaseOrder $order */
            if ($order->status === PurchaseStatus::Invoiced) {
                $add('actual', $order->category->value, (int) ($order->invoiced_cents ?? $order->totalCents()), $order->invoiced_on?->toDateString(), $order->cost_center_id);
            } elseif (in_array($order->status, [PurchaseStatus::Approved, PurchaseStatus::Received], true)) {
                $received = $order->status === PurchaseStatus::Received ? ($order->received_at?->toDateString() ?? $order->updated_at?->toDateString()) : null;
                $add('committed', $order->category->value, $order->totalCents(), null, $order->cost_center_id, ['received_on' => $received]);
            }
        }

        foreach ($project->contracts()->with(['items', 'certificates'])->get() as $contract) {
            /** @var Contract $contract */
            $certified = 0;
            foreach ($contract->certificates as $certificate) {
                /** @var Certificate $certificate */
                if ($certificate->status === CertificateStatus::Approved) {
                    $certified += $certificate->amount_cents;
                    $add('actual', CostCategory::Subcontract->value, $certificate->amount_cents, $certificate->period_end->toDateString(), null);
                }
            }
            if ($contract->status === ContractStatus::Active) {
                $add('committed', CostCategory::Subcontract->value, max(0, $contract->valueCents() - $certified), null, null);
            }
        }

        foreach ($project->timeEntries()->where('status', TimeEntryStatus::Approved->value)->get() as $entry) {
            /** @var TimeEntry $entry */
            $add('actual', CostCategory::Labor->value, $entry->costCents(), $entry->work_date->toDateString(), null);
        }

        foreach ($project->equipmentUsages()->get() as $usage) {
            /** @var EquipmentUsage $usage */
            $add('actual', CostCategory::Equipment->value, $usage->costCents(), $usage->used_on->toDateString(), null);
        }

        foreach ($project->costEntries()->get() as $entry) {
            /** @var CostEntry $entry */
            $kind = $entry->kind === 'committed' ? 'committed' : 'actual';
            $add($kind, $entry->category->value, $entry->amount_cents, $kind === 'actual' ? $entry->incurred_on->toDateString() : null, $entry->cost_center_id);
        }

        return $rows;
    }

    /**
     * @param  list<array{kind: string, category: string, cents: int, date: string|null, center_id: int|null}>|null  $movements  os de movements(), se já calculados
     * @return array<string, mixed>
     */
    public static function for(Project $project, ?array $movements = null): array
    {
        $categories = array_map(fn (CostCategory $c) => $c->value, CostCategory::cases());
        $zero = array_fill_keys($categories, 0);
        $planned = $zero;
        $buckets = ['committed' => $zero, 'actual' => $zero];
        $byCenter = [];
        $months = [];
        $defaultCenter = $project->cost_center_id;

        $budget = $project->budgets()->where('status', BudgetStatus::Approved->value)->latest('approved_at')->first();
        $budgetTotals = $budget ? BudgetCalculator::totals($budget) : null;
        if ($budgetTotals) {
            $planned = array_merge($planned, $budgetTotals['by_category']);
        }

        foreach ($movements ?? self::movements($project) as $m) {
            $kind = $m['kind'] === 'committed' ? 'committed' : 'actual';
            $buckets[$kind][$m['category']] += $m['cents'];
            $key = $m['center_id'] ?? $defaultCenter ?? 0;
            $byCenter[$key] ??= ['committed_cents' => 0, 'actual_cents' => 0];
            $byCenter[$key][$kind.'_cents'] += $m['cents'];
            if ($kind === 'actual' && $m['date'] !== null) {
                $month = substr($m['date'], 0, 7);
                $months[$month] = ($months[$month] ?? 0) + $m['cents'];
            }
        }
        $committed = $buckets['committed'];
        $actual = $buckets['actual'];

        $plannedTotal = array_sum($planned);
        $committedTotal = array_sum($committed);
        $actualTotal = array_sum($actual);
        $forecast = $actualTotal + $committedTotal;
        $ceiling = $project->cost_ceiling_cents ?? ($budgetTotals ? $plannedTotal : null);
        $certified = (int) $project->certificates()->where('scope', 'owner')->where('status', CertificateStatus::Approved->value)->sum('amount_cents');
        ksort($months);

        return [
            'budget' => $budget ? self::budgetRef($budget, $budgetTotals) : null,
            'ceiling_cents' => $ceiling,
            'ceiling_is_custom' => $project->cost_ceiling_cents !== null,
            'planned_cents' => $plannedTotal,
            'committed_cents' => $committedTotal,
            'actual_cents' => $actualTotal,
            'forecast_cents' => $forecast,
            'ceiling_used_pct' => $ceiling ? round($forecast * 100 / $ceiling, 1) : null,
            'alert' => self::alert($forecast, $ceiling),
            'revenue' => [
                'contract_cents' => $budgetTotals['sale_cents'] ?? ($project->contract_value_cents ?? null),
                'certified_cents' => $certified,
                'certified_pct' => ($budgetTotals['sale_cents'] ?? 0) > 0 ? round($certified * 100 / $budgetTotals['sale_cents'], 1) : null,
            ],
            'margin_forecast_cents' => isset($budgetTotals['sale_cents']) ? $budgetTotals['sale_cents'] - $forecast : null,
            'by_category' => array_map(fn (CostCategory $c) => [
                'category' => $c->value,
                'label' => $c->label(),
                'planned_cents' => $planned[$c->value],
                'committed_cents' => $committed[$c->value],
                'actual_cents' => $actual[$c->value],
                'forecast_cents' => $committed[$c->value] + $actual[$c->value],
            ], CostCategory::cases()),
            'by_cost_center' => $byCenter,
            'monthly_actual' => collect($months)->map(fn ($cents, $m) => ['month' => $m, 'actual_cents' => $cents])->values()->all(),
        ];
    }

    /** ok, warning (a partir de WARNING_PCT do teto) ou over; null sem teto. */
    public static function alert(int $cents, ?int $ceiling): ?string
    {
        if ($ceiling === null) {
            return null;
        }

        return $cents > $ceiling ? 'over' : ($cents * 100 >= $ceiling * self::WARNING_PCT ? 'warning' : 'ok');
    }

    /** @param  array<string, mixed>  $totals */
    private static function budgetRef(Budget $budget, array $totals): array
    {
        return ['id' => $budget->id, 'code' => $budget->code, 'version' => $budget->version, 'cost_cents' => $totals['cost_cents'], 'sale_cents' => $totals['sale_cents']];
    }
}
