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
 * O teto é o da obra (se escrito) ou o custo previsto. Previsão final = realizado + comprometido.
 */
final class CostReport
{
    /** A partir daqui (% do teto) a previsão final fica em alerta. */
    public const WARNING_PCT = 90;

    /** @return array<string, mixed> */
    public static function for(Project $project): array
    {
        $categories = array_map(fn (CostCategory $c) => $c->value, CostCategory::cases());
        $zero = array_fill_keys($categories, 0);
        $planned = $zero;
        $committed = $zero;
        $actual = $zero;
        $byCenter = [];
        $months = [];
        $defaultCenter = $project->cost_center_id;
        $add = function (array &$bucket, string $category, int $cents) {
            $bucket[$category] += $cents;
        };
        $center = function (?int $centerId, string $kind, int $cents) use (&$byCenter, $defaultCenter) {
            $key = $centerId ?? $defaultCenter ?? 0;
            $byCenter[$key] ??= ['committed_cents' => 0, 'actual_cents' => 0];
            $byCenter[$key][$kind.'_cents'] += $cents;
        };
        $month = function (?string $date, int $cents) use (&$months) {
            if ($date !== null) {
                $key = substr($date, 0, 7);
                $months[$key] = ($months[$key] ?? 0) + $cents;
            }
        };

        $budget = $project->budgets()->where('status', BudgetStatus::Approved->value)->latest('approved_at')->first();
        $budgetTotals = $budget ? BudgetCalculator::totals($budget) : null;
        if ($budgetTotals) {
            $planned = array_merge($planned, $budgetTotals['by_category']);
        }

        foreach ($project->purchaseOrders()->with('lines')->get() as $order) {
            /** @var PurchaseOrder $order */
            $category = $order->category->value;
            if ($order->status === PurchaseStatus::Invoiced) {
                $cents = (int) ($order->invoiced_cents ?? $order->totalCents());
                $add($actual, $category, $cents);
                $center($order->cost_center_id, 'actual', $cents);
                $month($order->invoiced_on?->toDateString(), $cents);
            } elseif (in_array($order->status, [PurchaseStatus::Approved, PurchaseStatus::Received], true)) {
                $cents = $order->totalCents();
                $add($committed, $category, $cents);
                $center($order->cost_center_id, 'committed', $cents);
            }
        }

        foreach ($project->contracts()->with(['items', 'certificates'])->get() as $contract) {
            /** @var Contract $contract */
            $certified = 0;
            foreach ($contract->certificates as $certificate) {
                /** @var Certificate $certificate */
                if ($certificate->status === CertificateStatus::Approved) {
                    $certified += $certificate->amount_cents;
                    $add($actual, CostCategory::Subcontract->value, $certificate->amount_cents);
                    $center(null, 'actual', $certificate->amount_cents);
                    $month($certificate->period_end->toDateString(), $certificate->amount_cents);
                }
            }
            if ($contract->status === ContractStatus::Active) {
                $remaining = max(0, $contract->valueCents() - $certified);
                $add($committed, CostCategory::Subcontract->value, $remaining);
                $center(null, 'committed', $remaining);
            }
        }

        foreach ($project->timeEntries()->where('status', TimeEntryStatus::Approved->value)->get() as $entry) {
            /** @var TimeEntry $entry */
            $cents = $entry->costCents();
            $add($actual, CostCategory::Labor->value, $cents);
            $center(null, 'actual', $cents);
            $month($entry->work_date->toDateString(), $cents);
        }

        foreach ($project->equipmentUsages()->get() as $usage) {
            /** @var EquipmentUsage $usage */
            $cents = $usage->costCents();
            $add($actual, CostCategory::Equipment->value, $cents);
            $center(null, 'actual', $cents);
            $month($usage->used_on->toDateString(), $cents);
        }

        foreach ($project->costEntries()->get() as $entry) {
            /** @var CostEntry $entry */
            $kind = $entry->kind === 'committed' ? 'committed' : 'actual';
            if ($kind === 'committed') {
                $add($committed, $entry->category->value, $entry->amount_cents);
            } else {
                $add($actual, $entry->category->value, $entry->amount_cents);
                $month($entry->incurred_on->toDateString(), $entry->amount_cents);
            }
            $center($entry->cost_center_id, $kind, $entry->amount_cents);
        }

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
            'alert' => $ceiling === null ? null : ($forecast > $ceiling ? 'over' : ($forecast * 100 >= $ceiling * self::WARNING_PCT ? 'warning' : 'ok')),
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

    /** @param  array<string, mixed>  $totals */
    private static function budgetRef(Budget $budget, array $totals): array
    {
        return ['id' => $budget->id, 'code' => $budget->code, 'version' => $budget->version, 'cost_cents' => $totals['cost_cents'], 'sale_cents' => $totals['sale_cents']];
    }
}
