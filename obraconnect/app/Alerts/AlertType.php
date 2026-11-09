<?php

namespace App\Alerts;

/**
 * Tipos de alerta automático. Cada um diz a que parte da obra pertence (e por isso quem o vê) e
 * para que separador aponta.
 */
enum AlertType: string
{
    case CostCeiling = 'cost_ceiling';
    case CostWorse = 'cost_worse';
    case FinishContract = 'finish_contract';
    case FinishWorse = 'finish_worse';
    case MeasurementsStale = 'measurements_stale';
    case HoldPoint = 'hold_point';
    case SupplierDocs = 'supplier_docs';

    public function label(): string
    {
        return match ($this) {
            self::CostCeiling => 'Custo previsto perto ou acima do teto',
            self::CostWorse => 'Custo previsto subiu numa semana',
            self::FinishContract => 'Fim previsto depois do prazo contratual',
            self::FinishWorse => 'Fim previsto atrasou numa semana',
            self::MeasurementsStale => 'Medições atrasadas',
            self::HoldPoint => 'Ponto de paragem por libertar',
            self::SupplierDocs => 'Faturas de fornecedores por rever',
        };
    }

    /** Quem vê o alerta: basta uma destas permissões (na obra, ou na empresa para os alertas sem obra). @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::CostCeiling, self::CostWorse => ['costs.view', 'costs.manage', 'finance.view'],
            self::FinishContract, self::FinishWorse => ['planning.view', 'planning.manage'],
            self::MeasurementsStale => ['measurements.manage', 'costs.view', 'costs.manage'],
            self::HoldPoint => ['inspections.request', 'inspections.perform', 'inspections.approve', 'inspections.release_hold_point'],
            self::SupplierDocs => ['supplier_docs.manage'],
        };
    }

    public function tab(): ?string
    {
        return match ($this) {
            self::CostCeiling, self::CostWorse, self::FinishContract, self::FinishWorse => 'previsoes',
            self::MeasurementsStale => 'medicoes',
            self::HoldPoint => 'inspecoes',
            self::SupplierDocs => null,
        };
    }

    public function isCompanyWide(): bool
    {
        return $this === self::SupplierDocs;
    }

    public function companyPath(): ?string
    {
        return $this === self::SupplierDocs ? '/app/faturas-fornecedores' : null;
    }
}
