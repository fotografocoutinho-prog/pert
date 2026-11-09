<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\SupplierDocumentKind;
use App\Enums\SupplierDocumentStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura, nota de crédito, talão ou guia de um fornecedor. Lida pelo DocIntel (ou preenchida à mão),
 * revista e lançada: na compra da obra (fica faturada ou recebida) ou como custo da obra.
 */
#[Fillable([
    'project_id', 'partner_id', 'purchase_order_id', 'cost_center_id', 'kind', 'category',
    'supplier_name', 'supplier_nif', 'customer_nif', 'number', 'atcud', 'issue_date', 'due_date',
    'order_reference', 'net_cents', 'vat_cents', 'gross_cents', 'notes',
])]
class SupplierDocument extends Model
{
    use BelongsToTenant;

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    protected $attributes = [
        'kind' => 'invoice',
        'status' => 'to_review',
        'category' => 'material',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SupplierDocumentKind::class,
            'status' => SupplierDocumentStatus::class,
            'category' => CostCategory::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'confidence' => 'float',
            'review_reasons' => 'array',
            'lines' => 'array',
            'net_cents' => 'integer',
            'vat_cents' => 'integer',
            'gross_cents' => 'integer',
            'size_bytes' => 'integer',
            'read_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function directory(): string
    {
        return "tenants/{$this->tenant_id}/supplier-documents/{$this->id}";
    }

    public function isEditable(): bool
    {
        return $this->status === SupplierDocumentStatus::ToReview;
    }

    /** Sem IVA; se só houver o total e o IVA, faz a diferença. */
    public function netCents(): ?int
    {
        if ($this->net_cents !== null) {
            return $this->net_cents;
        }

        return $this->gross_cents !== null && $this->vat_cents !== null ? $this->gross_cents - $this->vat_cents : null;
    }

    /** "FT 2026/12 · MaterMarquês, Lda" (para as descrições dos custos e da auditoria). */
    public function label(): string
    {
        $name = $this->partner?->name ?? $this->supplier_name ?? 'fornecedor por identificar';

        return trim(($this->number ?: $this->kind->label()).' · '.$name);
    }
}
