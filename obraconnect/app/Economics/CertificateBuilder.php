<?php

namespace App\Economics;

use App\Enums\CertificateStatus;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\CertificateLine;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monta um auto de medição. Ao dono de obra: as medições ainda não autadas até ao fim do período,
 * aos preços de venda do orçamento aprovado. De subempreitada: uma linha por artigo do contrato,
 * com a quantidade do período a escrever. Em ambos, "anterior" = soma dos autos aprovados.
 */
final class CertificateBuilder
{
    /** @param  array{period_start: string, period_end: string, notes?: string|null}  $data */
    public static function create(Project $project, array $data, ?Contract $contract, ?Budget $budget, ?int $userId): Certificate
    {
        return DB::transaction(function () use ($project, $data, $contract, $budget, $userId): Certificate {
            // Bloqueia a obra para o número do auto não se repetir em pedidos simultâneos.
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $scope = $contract ? "contract:{$contract->id}" : 'owner';
            $certificate = $project->certificates()->make(['period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'notes' => $data['notes'] ?? null]);
            $certificate->scope = $scope;
            $certificate->number = (int) $project->certificates()->where('scope', $scope)->max('number') + 1;
            $certificate->budget_id = $budget?->id;
            $certificate->contract_id = $contract?->id;
            $certificate->created_by_user_id = $userId;
            $certificate->save();

            $contract ? self::linesFromContract($certificate, $contract) : self::linesFromMeasurements($project, $certificate, $budget);
            self::recompute($certificate);

            return $certificate;
        });
    }

    public static function recompute(Certificate $certificate): void
    {
        $amount = (int) $certificate->lines()->sum('amount_cents');
        $retention = $certificate->contract_id ? (int) round($amount * (float) $certificate->contract->retention_pct / 100) : 0;
        $certificate->forceFill(['amount_cents' => $amount, 'retention_cents' => $retention])->save();
    }

    private static function linesFromMeasurements(Project $project, Certificate $certificate, ?Budget $budget): void
    {
        if ($budget === null) {
            return;
        }
        $budget->load('items');
        $pending = $project->measurements()->whereNull('certificate_id')->whereDate('measured_on', '<=', $certificate->period_end->toDateString())
            ->whereIn('budget_item_id', $budget->items->pluck('id'))->get();
        $previous = self::previousQuantities($project, 'owner', 'budget_item_id');
        $order = 0;
        foreach ($budget->items as $item) {
            $period = (float) $pending->where('budget_item_id', $item->id)->sum(fn ($m) => (float) $m->quantity);
            $before = (float) ($previous[$item->id] ?? 0);
            if ($period == 0.0 && $before == 0.0) {
                continue;
            }
            $price = $item->salePriceCents($budget);
            $certificate->lines()->create([
                'budget_item_id' => $item->id, 'code' => $item->code, 'description' => $item->description, 'unit' => $item->unit,
                'unit_price_cents' => $price, 'qty_contract' => (float) $item->quantity, 'qty_previous' => $before, 'qty_period' => $period,
                'amount_cents' => (int) round($period * $price), 'sort_order' => $order++,
            ]);
        }
        $pending->each(fn ($m) => $m->forceFill(['certificate_id' => $certificate->id])->save());
    }

    private static function linesFromContract(Certificate $certificate, Contract $contract): void
    {
        $previous = self::previousQuantities($certificate->project, "contract:{$contract->id}", 'contract_item_id');
        foreach ($contract->items as $i => $item) {
            $certificate->lines()->create([
                'contract_item_id' => $item->id, 'code' => (string) ($i + 1), 'description' => $item->description, 'unit' => $item->unit,
                'unit_price_cents' => $item->unit_price_cents, 'qty_contract' => (float) $item->quantity,
                'qty_previous' => (float) ($previous[$item->id] ?? 0), 'qty_period' => 0, 'amount_cents' => 0, 'sort_order' => $i,
            ]);
        }
    }

    /** Quantidades já em autos aprovados do mesmo âmbito. @return Collection<int|string, mixed> */
    private static function previousQuantities(Project $project, string $scope, string $column): Collection
    {
        return CertificateLine::query()
            ->whereHas('certificate', fn ($q) => $q->where('project_id', $project->id)->where('scope', $scope)->where('status', CertificateStatus::Approved->value))
            ->whereNotNull($column)->groupBy($column)->selectRaw("{$column} as k, sum(qty_period) as q")->pluck('q', 'k');
    }
}
