<?php

namespace App\Integrations;

use App\Invoicing\InvoiceExport;
use App\Models\Certificate;
use App\Models\CertificateLine;
use App\Models\InvoiceDraft;
use App\Models\Project;
use App\Models\ProjectAlert;
use App\Models\SupplierDocument;

/**
 * Formato dos objetos na API pública e nos webhooks (o mesmo nos dois). Estável: podem aparecer campos
 * novos, os existentes não mudam de nome nem de significado. Valores em cêntimos; datas ISO 8601.
 */
final class Payloads
{
    public static function project(Project $p): array
    {
        $p->loadMissing('client:id,name');

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'status' => $p->status->value,
            'client' => $p->client?->name,
            'city' => $p->city,
            'starts_on' => $p->starts_on?->toDateString(),
            'planned_end_on' => $p->planned_end_on?->toDateString(),
            'contract_value_cents' => $p->contract_value_cents,
            'cost_ceiling_cents' => $p->cost_ceiling_cents,
        ];
    }

    public static function certificate(Certificate $c): array
    {
        $c->loadMissing(['lines', 'contract.partner:id,name,nif', 'project:id,code,name']);

        return [
            'id' => $c->id,
            'project' => ['id' => $c->project_id, 'code' => $c->project?->code, 'name' => $c->project?->name],
            'number' => $c->number,
            'kind' => $c->isOwner() ? 'owner' : 'subcontract',
            'subcontract' => $c->contract ? [
                'code' => $c->contract->code,
                'title' => $c->contract->title,
                'partner' => $c->contract->partner?->name,
                'partner_nif' => $c->contract->partner?->nif,
            ] : null,
            'period_start' => $c->period_start?->toDateString(),
            'period_end' => $c->period_end?->toDateString(),
            'status' => $c->status->value,
            'amount_cents' => $c->amount_cents,
            'retention_cents' => $c->retention_cents,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'lines' => $c->lines->filter(fn (CertificateLine $l) => (float) $l->qty_period != 0.0)->values()->map(fn (CertificateLine $l) => [
                'code' => $l->code,
                'description' => $l->description,
                'unit' => $l->unit,
                'quantity' => (float) $l->qty_period,
                'unit_price_cents' => $l->unit_price_cents,
                'amount_cents' => $l->amount_cents,
            ])->all(),
        ];
    }

    /** Pré-fatura no formato da exportação JSON (para o programa de faturação), com o id. */
    public static function invoiceDraft(InvoiceDraft $d): array
    {
        $d->loadMissing(['lines', 'project:id,code']);

        return ['id' => $d->id] + InvoiceExport::json(collect([$d]))[0];
    }

    public static function supplierDocument(SupplierDocument $d): array
    {
        $d->loadMissing('project:id,code');

        return [
            'id' => $d->id,
            'status' => $d->status->value,
            'kind' => $d->kind->value,
            'project_code' => $d->project?->code,
            'supplier_name' => $d->supplier_name,
            'supplier_nif' => $d->supplier_nif,
            'number' => $d->number,
            'atcud' => $d->atcud,
            'issue_date' => $d->issue_date?->toDateString(),
            'net_cents' => $d->net_cents,
            'vat_cents' => $d->vat_cents,
            'gross_cents' => $d->gross_cents,
            'read_by' => $d->reader,
            'read_at' => $d->read_at?->toIso8601String(),
            'read_error' => $d->read_error !== null,
            'review_reasons' => $d->review_reasons ?? [],
            'original_name' => $d->original_name,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    public static function alert(ProjectAlert $a): array
    {
        $a->loadMissing('project:id,code,name');

        return [
            'id' => $a->id,
            'type' => $a->type->value,
            'level' => $a->level,
            'title' => $a->title,
            'detail' => $a->detail,
            'project' => $a->project ? ['id' => $a->project->id, 'code' => $a->project->code, 'name' => $a->project->name] : null,
            'opened_at' => $a->opened_at?->toIso8601String(),
            'url' => url($a->path()),
        ];
    }
}
