<?php

namespace App\Models;

use App\Enums\InvoiceDraftStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pré-fatura: documento interno, sem valor fiscal, para exportar para o programa de faturação
 * certificado. Rascunho → pronta a faturar (recebe o número interno) → faturada (com o número e a
 * data da fatura oficial) ou anulada.
 */
#[Fillable([
    'project_id', 'client_id', 'issue_date', 'due_date',
    'client_name', 'client_nif', 'client_address', 'client_postal_code', 'client_city', 'client_country', 'client_email',
    'reference', 'vat_exemption_code', 'vat_exemption_reason', 'notes',
])]
class InvoiceDraft extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
        'client_country' => 'PT',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceDraftStatus::class,
            'series_year' => 'integer',
            'number' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'official_date' => 'date',
            'ready_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceDraftLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function invoicedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoiced_by_user_id');
    }

    /** "PF 2026/0007", ou null enquanto é rascunho. */
    public function code(): ?string
    {
        return $this->number ? sprintf('PF %d/%04d', $this->series_year, $this->number) : null;
    }

    public function isEditable(): bool
    {
        return $this->status === InvoiceDraftStatus::Draft;
    }

    /**
     * Totais com IVA arredondado linha a linha (como sai na exportação).
     *
     * @return array{net_cents: int, vat_cents: int, total_cents: int, by_rate: list<array{rate: float, net_cents: int, vat_cents: int}>, lines: array<int, array{net_cents: int, vat_cents: int}>}
     */
    public function totals(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        $byRate = [];
        $perLine = [];
        foreach ($lines as $line) {
            [$net, $vat] = $line->amounts();
            $perLine[$line->id] = ['net_cents' => $net, 'vat_cents' => $vat];
            $key = number_format((float) $line->vat_rate, 2, '.', '');
            $byRate[$key] ??= ['rate' => (float) $line->vat_rate, 'net_cents' => 0, 'vat_cents' => 0];
            $byRate[$key]['net_cents'] += $net;
            $byRate[$key]['vat_cents'] += $vat;
        }
        krsort($byRate, SORT_NUMERIC);
        $net = array_sum(array_column($byRate, 'net_cents'));
        $vat = array_sum(array_column($byRate, 'vat_cents'));

        return ['net_cents' => $net, 'vat_cents' => $vat, 'total_cents' => $net + $vat, 'by_rate' => array_values($byRate), 'lines' => $perLine];
    }
}
