<?php

namespace App\Forecasting;

use App\Economics\BudgetCalculator;
use App\Economics\CostReport;
use App\Enums\BudgetStatus;
use App\Enums\CostCategory;
use App\Enums\ProjectStatus;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Measurement;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Previsão do custo da obra no fim, explicada. Sem IA: as contas do controlo de custos.
 *
 * - Trabalho medido (a preço de custo do orçamento aprovado) = Σ quantidade medida × custo unitário.
 * - O que falta = Σ (quantidade orçamentada − medida, se positiva) × custo unitário.
 * - Custo até à última medição: o realizado e o material recebido ainda por faturar até essa data, o
 *   que corresponde ao trabalho medido (o que entrou depois é de trabalho ainda por medir e já está
 *   dentro do "que falta").
 * - Ritmo de custo = custo até à última medição ÷ trabalho medido (1,10 = cada euro orçamentado está a
 *   custar 1,10 €). Mostra-se com MIN_PROGRESS_PCT medido; manda na previsão com TREND_HEADLINE_PCT.
 * - Previsão = custo até à última medição + o que falta (× ritmo, quando manda), nunca abaixo do já
 *   gasto mais o comprometido (encomendas e contratos que vão ser pagos).
 */
final class CostForecast
{
    /** Abaixo disto (% do trabalho medido) a tendência ainda não diz nada. */
    public const MIN_PROGRESS_PCT = 15;

    /** A partir daqui (% do trabalho medido) o ritmo de custo passa a mandar na previsão. */
    public const TREND_HEADLINE_PCT = 50;

    /** Medições mais antigas do que isto (dias) numa obra em curso tiram confiança à previsão. */
    public const STALE_DAYS = 30;

    /**
     * @param  list<array{kind: string, category: string, cents: int, date: string|null, center_id: int|null}>|null  $movements
     * @param  array<string, mixed>|null  $report  o CostReport::for() da obra, se já calculado
     * @return array<string, mixed>
     */
    public static function for(Project $project, CarbonImmutable $today, ?array $movements = null, ?array $report = null): array
    {
        $movements ??= CostReport::movements($project);
        $report ??= CostReport::for($project, $movements);
        $actual = (int) $report['actual_cents'];
        $committed = (int) $report['committed_cents'];

        /** @var Budget|null $budget */
        $budget = $project->budgets()->where('status', BudgetStatus::Approved->value)->latest('approved_at')->with(['chapters', 'items.components'])->first();
        if ($budget === null) {
            return [
                'available' => false,
                'message' => 'Sem orçamento aprovado não há base para prever o custo no fim: aprove o orçamento da obra.',
                'actual_cents' => $actual,
                'committed_cents' => $committed,
            ];
        }
        $totals = BudgetCalculator::totals($budget);
        $bac = (int) $totals['cost_cents'];

        // Medições: as do orçamento aprovado e as de versões anteriores (pelo código do artigo).
        $items = $budget->items->keyBy('id');
        $byCode = $budget->items->keyBy('code');
        $measured = [];
        $lastMeasured = null;
        $unmatched = 0;
        $fromRevisions = 0;
        $rows = Measurement::query()->where('project_id', $project->id)->with('item:id,budget_id,code')->get(['id', 'budget_item_id', 'quantity', 'measured_on']);
        foreach ($rows as $row) {
            /** @var Measurement $row */
            $item = $items->get($row->budget_item_id);
            if ($item === null && $row->item !== null && $byCode->has($row->item->code)) {
                $item = $byCode->get($row->item->code);
                $fromRevisions++;
            }
            if ($item === null) {
                $unmatched++;

                continue;
            }
            $measured[$item->id] = ($measured[$item->id] ?? 0.0) + (float) $row->quantity;
            $date = $row->measured_on->toDateString();
            $lastMeasured = $lastMeasured === null || $date > $lastMeasured ? $date : $lastMeasured;
        }

        $earned = 0.0;
        $remaining = 0.0;
        $overruns = [];
        foreach ($budget->items as $item) {
            /** @var BudgetItem $item */
            $q = (float) $item->quantity;
            $m = max(0.0, $measured[$item->id] ?? 0.0);
            $uc = (int) $item->unit_cost_cents;
            $earned += $m * $uc;
            $remaining += max(0.0, $q - $m) * $uc;
            if ($uc > 0 && $m > $q + 0.0005) {
                $overruns[] = ['item' => $item, 'measured' => $m, 'budgeted' => $q, 'extra_cents' => (int) round(($m - $q) * $uc)];
            }
        }
        $earned = (int) round($earned);
        $remaining = (int) round($remaining);
        $progress = $bac > 0 ? $earned * 100 / $bac : 0.0;

        // Custo incorrido: o realizado e o material recebido ainda por faturar (já está em obra). Até à
        // data da última medição é o que corresponde ao trabalho medido (sem data: conta, por prudência).
        $received = 0;
        $incurredToMeasurement = 0;
        $receivedToMeasurement = 0;
        foreach ($movements as $mv) {
            $receivedOn = $mv['received_on'] ?? null;
            if ($mv['kind'] === 'committed' && $receivedOn !== null) {
                $received += $mv['cents'];
            }
            if ($lastMeasured === null) {
                continue;
            }
            if ($mv['kind'] === 'actual' && ($mv['date'] === null || $mv['date'] <= $lastMeasured)) {
                $incurredToMeasurement += $mv['cents'];
            } elseif ($receivedOn !== null && $receivedOn <= $lastMeasured) {
                $incurredToMeasurement += $mv['cents'];
                $receivedToMeasurement += $mv['cents'];
            }
        }
        $incurred = $actual + $received;
        $incurredAfter = $incurred - $incurredToMeasurement;

        // Ritmo de custo: mostra-se a partir de MIN_PROGRESS_PCT medido; manda na previsão a partir de
        // TREND_HEADLINE_PCT (antes disso, compras feitas de uma vez no início pesam demasiado).
        $costPerEuro = $earned > 0 && $incurredToMeasurement > 0 ? $incurredToMeasurement / $earned : null;
        $trendAvailable = $lastMeasured !== null && $costPerEuro !== null && $progress >= self::MIN_PROGRESS_PCT;
        $trendHeadline = $trendAvailable && $progress >= self::TREND_HEADLINE_PCT;
        $floor = $actual + $committed;
        $budgetPath = $incurredToMeasurement + $remaining;
        $trendPath = $trendAvailable ? $incurredToMeasurement + (int) round($remaining * $costPerEuro) : null;
        $atBudget = max($budgetPath, $floor);
        $atTrend = $trendPath === null ? null : max($trendPath, $floor);
        $forecast = $trendHeadline ? $atTrend : $atBudget;
        $path = $trendHeadline ? $trendPath : $budgetPath;
        $floorBinds = $floor > $path;

        $ceiling = $report['ceiling_cents'] === null ? null : (int) $report['ceiling_cents'];
        $sale = (int) $totals['sale_cents'];
        $margin = $sale - $forecast;
        $marginPct = $sale > 0 ? round($margin * 100 / $sale, 1) : null;
        $budgetMarginPct = $totals['margin_pct'] === null ? null : (float) $totals['margin_pct'];

        $age = $lastMeasured === null ? null : (int) CarbonImmutable::parse($lastMeasured)->diffInDays($today);
        $running = in_array($project->status, [ProjectStatus::Active, ProjectStatus::Suspended], true);
        $stale = $age !== null && $age > self::STALE_DAYS && $running;
        [$confidence, $confidenceText] = self::confidence($lastMeasured, $progress, $stale);

        $reasons = [];
        $money = fn (int $c) => Explain::money($c);

        // Teto.
        $alert = CostReport::alert($forecast, $ceiling);
        if ($alert === 'over') {
            $reasons[] = Explain::reason('danger', "A previsão ({$money($forecast)}) passa o teto da obra ({$money($ceiling)}) em {$money($forecast - $ceiling)} (".Explain::pct(($forecast - $ceiling) * 100 / max(1, $ceiling), 1).').', 'custos');
        } elseif ($alert === 'warning') {
            $reasons[] = Explain::reason('warning', 'A previsão já vai em '.Explain::pct($forecast * 100 / max(1, $ceiling), 1)." do teto da obra ({$money($ceiling)}).", 'custos');
        }

        // Margem.
        if ($sale > 0) {
            if ($margin < 0) {
                $reasons[] = Explain::reason('danger', "Com esta previsão a obra dá prejuízo: {$money($margin)} (".Explain::pct((float) $marginPct, 1).' da venda).');
            } elseif ($budgetMarginPct !== null && $marginPct !== null && $marginPct < $budgetMarginPct - 2) {
                $reasons[] = Explain::reason('warning', 'A margem prevista desce de '.Explain::pct($budgetMarginPct, 1).' (no orçamento) para '.Explain::pct($marginPct, 1).'.');
            } elseif ($budgetMarginPct !== null && $marginPct !== null && $marginPct > $budgetMarginPct + 2) {
                $reasons[] = Explain::reason('good', 'A margem prevista sobe de '.Explain::pct($budgetMarginPct, 1).' (no orçamento) para '.Explain::pct($marginPct, 1).'.');
            }
        }

        // Ritmo de custo.
        $measuredUntil = Explain::date($lastMeasured);
        if ($trendAvailable) {
            $factor = Explain::number($costPerEuro, 2, false);
            $receivedNote = $receivedToMeasurement > 0 ? " (com {$money($receivedToMeasurement)} de material recebido ainda por faturar)" : '';
            $base = "Até {$measuredUntil} mediu-se {$money($earned)} de trabalho (a preço de custo do orçamento) e o custo até essa data foi {$money($incurredToMeasurement)}{$receivedNote}: cada 1,00 € orçamentado está a custar {$factor} €.";
            $level = $costPerEuro > 1.03 ? 'warning' : ($costPerEuro < 0.97 ? 'good' : 'info');
            if ($level === 'info') {
                $text = "{$base} Em linha com o orçamento.";
            } elseif ($trendHeadline) {
                $text = $base.($level === 'good' ? ' Abaixo do orçamento.' : '')
                    .($remaining > 0 ? " Se o que falta seguir este ritmo, custa {$money((int) round($remaining * $costPerEuro))} em vez de {$money($remaining)}." : '');
            } else {
                $text = "{$base} Se o que falta seguir este ritmo, a obra custa {$money($atTrend)}"
                    .($ceiling !== null && $atTrend > $ceiling ? ', acima do teto' : '')
                    .'. Com '.Explain::pct($progress, 0).' medido ainda é cedo para confiar no ritmo (as compras feitas de uma vez pesam muito), por isso a previsão conta o que falta ao preço do orçamento.';
            }
            $reasons[] = Explain::reason($level, $text, 'custos');
        } elseif ($lastMeasured === null && $incurred > 0) {
            $reasons[] = Explain::reason('warning', "Já há {$money($incurred)} de custos, mas nenhuma medição: sem medir o que está feito não se sabe se a obra está a correr bem. A previsão assume que tudo custa o orçamentado.", 'medicoes');
        } elseif ($lastMeasured === null) {
            $reasons[] = Explain::reason('info', 'Ainda não há medições nem custos: a previsão é o custo do orçamento.', 'medicoes');
        } elseif ($incurredToMeasurement <= 0) {
            $reasons[] = Explain::reason('info', "Há trabalho medido até {$measuredUntil}, mas nenhum custo até essa data: a previsão assume que o que falta custa o orçamentado.", 'custos');
        } else {
            $reasons[] = Explain::reason('info', 'Só '.Explain::pct($progress, 1).' do trabalho está medido: é cedo para tirar a tendência, por isso a previsão assume que o que falta custa o orçamentado (o ritmo até agora é de '.Explain::number((float) $costPerEuro, 2, false).' € por cada 1,00 € orçamentado).', 'medicoes');
        }

        // Já gasto + comprometido acima do cálculo.
        if ($floorBinds) {
            $reasons[] = Explain::reason('warning', "O realizado ({$money($actual)}) mais o comprometido em encomendas e contratos ({$money($committed)}) somam {$money($floor)}, mais do que dá a conta pelo trabalho que falta ({$money($path)}): a previsão não pode ficar abaixo do que já está gasto ou contratado.", 'compras');
        }

        // Quantidades acima do orçamento.
        usort($overruns, fn ($a, $b) => $b['extra_cents'] <=> $a['extra_cents']);
        foreach (array_slice($overruns, 0, 3) as $o) {
            /** @var BudgetItem $item */
            $item = $o['item'];
            $label = trim($item->code.' '.Str::limit($item->description, 60));
            $unit = $item->unit;
            $text = $o['budgeted'] > 0
                ? "{$label}: medidos ".Explain::number($o['measured'], 3)." {$unit} de ".Explain::number($o['budgeted'], 3).' orçamentados (+'.Explain::pct(($o['measured'] - $o['budgeted']) * 100 / $o['budgeted']).") — mais {$money($o['extra_cents'])} de custo."
                : "{$label}: medidos ".Explain::number($o['measured'], 3)." {$unit} sem quantidade no orçamento — {$money($o['extra_cents'])} de custo.";
            $reasons[] = Explain::reason('warning', $text, 'medicoes');
        }
        if (count($overruns) > 3) {
            $rest = array_slice($overruns, 3);
            $reasons[] = Explain::reason('info', 'E mais '.count($rest).' '.(count($rest) === 1 ? 'artigo' : 'artigos')." acima da quantidade orçamentada (+{$money(array_sum(array_column($rest, 'extra_cents')))}).", 'medicoes');
        }

        // Por natureza, quando o orçamento tem composições (sem elas, tudo é "outros").
        $planned = $totals['by_category'];
        if ($bac > 0 && ($planned[CostCategory::Other->value] ?? 0) * 2 < $bac) {
            foreach ($report['by_category'] as $row) {
                $spent = (int) $row['actual_cents'] + (int) $row['committed_cents'];
                $plan = (int) ($planned[$row['category']] ?? 0);
                if ($plan > 0 && $spent > $plan) {
                    $reasons[] = Explain::reason('warning', "{$row['label']}: realizado mais comprometido {$money($spent)}, acima do previsto no orçamento ({$money($plan)}, +".Explain::pct(($spent - $plan) * 100 / $plan).').', 'custos');
                } elseif ($plan === 0 && $spent * 100 > $bac && $row['category'] !== CostCategory::Other->value) {
                    $reasons[] = Explain::reason('info', "{$row['label']}: {$money($spent)} sem custo previsto no orçamento para esta natureza.", 'custos');
                }
            }
        }

        // Medições atrasadas.
        if ($stale) {
            $text = "A última medição é de {$measuredUntil} (há ".Explain::days($age).').';
            $text .= $incurredAfter > 0 ? " Desde então entraram {$money($incurredAfter)} de custos de trabalho que ainda não está medido: meça o executado para a previsão ficar certa." : ' Meça o executado para a previsão ficar certa.';
            $reasons[] = Explain::reason('warning', $text, 'medicoes');
        }
        if ($fromRevisions > 0) {
            $reasons[] = Explain::reason('info', "{$fromRevisions} ".($fromRevisions === 1 ? 'medição foi feita' : 'medições foram feitas').' sobre uma versão anterior do orçamento: contam no artigo com o mesmo código.', 'medicoes');
        }
        if ($unmatched > 0) {
            $reasons[] = Explain::reason('info', "{$unmatched} ".($unmatched === 1 ? 'medição é de um artigo' : 'medições são de artigos').' que já não existe no orçamento aprovado: não contam.', 'medicoes');
        }

        return [
            'available' => true,
            'budget' => ['id' => $budget->id, 'code' => $budget->code, 'version' => $budget->version],
            'bac_cents' => $bac,
            'earned_cents' => $earned,
            'progress_pct' => round($progress, 1),
            'remaining_cents' => $remaining,
            'actual_cents' => $actual,
            'incurred_cents' => $incurred,
            'received_cents' => $received,
            'incurred_to_measurement_cents' => $incurredToMeasurement,
            'committed_cents' => $committed,
            'last_measured_on' => $lastMeasured,
            'measurement_age_days' => $age,
            'cost_per_euro' => $costPerEuro === null ? null : round($costPerEuro, 3),
            'method' => $trendHeadline ? 'trend' : 'budget',
            'trend_available' => $trendAvailable,
            'forecast_cents' => $forecast,
            'at_budget_cents' => $atBudget,
            'at_trend_cents' => $atTrend,
            'floor_cents' => $floor,
            'floor_binds' => $floorBinds,
            'variance_cents' => $forecast - $bac,
            'ceiling_cents' => $ceiling,
            'ceiling_used_pct' => $ceiling ? round($forecast * 100 / $ceiling, 1) : null,
            'alert' => $alert,
            'sale_cents' => $sale,
            'margin_cents' => $margin,
            'margin_pct' => $marginPct,
            'budget_margin_pct' => $budgetMarginPct,
            'confidence' => $confidence,
            'confidence_text' => $confidenceText,
            'reasons' => Explain::sort($reasons),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function confidence(?string $lastMeasured, float $progress, bool $stale): array
    {
        if ($lastMeasured === null) {
            return ['low', 'Baixa: ainda não há medições.'];
        }
        $level = $progress < self::MIN_PROGRESS_PCT ? 0 : ($progress < 50 ? 1 : 2);
        $why = 'há '.Explain::pct($progress, 0).' do trabalho medido';
        if ($stale && $level > 0) {
            $level--;
            $why .= ', mas a última medição é antiga';
        }

        return [['low', 'medium', 'high'][$level], ['Baixa', 'Média', 'Alta'][$level].": {$why}."];
    }
}
