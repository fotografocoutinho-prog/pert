<?php

namespace App\Invoicing;

use App\Enums\CertificateStatus;
use App\Enums\InvoiceDraftStatus;
use App\Enums\VatMode;
use App\Exceptions\ApiException;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\InvoiceDraft;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Regras das pré-faturas que não dependem do pedido HTTP (usadas pela API e pela demonstração).
 */
final class InvoiceDrafts
{
    /** Menção habitual na fatura quando o IVA é autoliquidado pelo adquirente (confirmar com o contabilista). */
    public const REVERSE_CHARGE_REASON = 'IVA - autoliquidação';

    /** Pré-fatura em rascunho com as linhas deste período de um auto ao dono de obra aprovado. */
    public static function fromCertificate(Certificate $certificate, ?int $userId): InvoiceDraft
    {
        if ($certificate->contract_id !== null || $certificate->status !== CertificateStatus::Approved) {
            throw ApiException::conflict('Só se faturam autos ao dono de obra já aprovados.', 'CERTIFICATE_STATUS');
        }
        $existing = InvoiceDraft::query()->where('certificate_id', $certificate->id)->where('status', '!=', InvoiceDraftStatus::Cancelled->value)->first();
        if ($existing !== null) {
            throw ApiException::conflict('Este auto já tem uma pré-fatura ('.($existing->code() ?? 'rascunho').').', 'INVOICE_EXISTS');
        }

        $certificate->loadMissing(['project.client', 'budget', 'lines']);
        $project = $certificate->project;
        $budget = $certificate->budget;
        $rate = $budget?->effectiveVatRate() ?? 23.0;
        $reason = match ($budget?->vat_mode) {
            VatMode::ReverseCharge => self::REVERSE_CHARGE_REASON,
            default => null,
        };

        return DB::transaction(function () use ($certificate, $project, $rate, $reason, $userId): InvoiceDraft {
            $draft = new InvoiceDraft(self::clientFields($project->client, $project) + [
                'project_id' => $project->id,
                'reference' => sprintf('Auto de medição n.º %d (%s a %s)', $certificate->number, $certificate->period_start->format('d/m/Y'), $certificate->period_end->format('d/m/Y')),
                'vat_exemption_reason' => $reason,
                'due_date' => null,
            ]);
            $draft->certificate_id = $certificate->id;
            $draft->created_by_user_id = $userId;
            $draft->save();

            $order = 0;
            foreach ($certificate->lines->sortBy('sort_order') as $line) {
                if ((float) $line->qty_period == 0.0) {
                    continue;
                }
                $draft->lines()->create([
                    'code' => $line->code, 'description' => $line->description, 'unit' => $line->unit,
                    'quantity' => (float) $line->qty_period, 'unit_price_cents' => $line->unit_price_cents,
                    'discount_pct' => 0, 'vat_rate' => $rate, 'sort_order' => $order++,
                ]);
            }

            return $draft;
        });
    }

    /** @return array<string, mixed> cópia dos dados do cliente (ou do dono de obra, sem cliente) */
    public static function clientFields(?Client $client, ?Project $project = null): array
    {
        if ($client === null) {
            return ['client_id' => null, 'client_name' => $project?->owner_name ?: ($project?->name ?? 'Cliente'), 'client_country' => 'PT'];
        }

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'client_nif' => $client->nif,
            'client_address' => $client->address,
            'client_postal_code' => $client->postal_code,
            'client_city' => $client->city,
            'client_country' => $client->country_code ?: 'PT',
            'client_email' => $client->email,
        ];
    }

    /** Rascunho → pronta a faturar: verifica, dá a data e o número interno. */
    public static function markReady(InvoiceDraft $draft, ?string $issueDate = null): void
    {
        $draft->loadMissing('lines');
        if ($draft->lines->isEmpty()) {
            throw ApiException::unprocessable('A pré-fatura não tem linhas.', 'INVOICE_EMPTY');
        }
        if ($draft->lines->contains(fn ($l) => (float) $l->vat_rate == 0.0) && blank($draft->vat_exemption_reason)) {
            throw ApiException::unprocessable('Há linhas sem IVA: escreva o motivo da isenção (ou a menção de autoliquidação).', 'VAT_REASON_REQUIRED');
        }

        DB::transaction(function () use ($draft, $issueDate): void {
            $date = $issueDate ?? $draft->issue_date?->toDateString() ?? now('Europe/Lisbon')->toDateString();
            $year = (int) substr($date, 0, 4);
            // Bloqueia a empresa para dois pedidos ao mesmo tempo não terem o mesmo número.
            Tenant::query()->whereKey($draft->tenant_id)->lockForUpdate()->first();
            if ($draft->number === null || $draft->series_year !== $year) {
                $draft->series_year = $year;
                $draft->number = (int) InvoiceDraft::query()->where('series_year', $year)->max('number') + 1;
            }
            $draft->issue_date = $date;
            $draft->status = InvoiceDraftStatus::Ready;
            $draft->ready_at = now();
            $draft->save();
        });
    }

    public static function markInvoiced(InvoiceDraft $draft, string $officialNumber, string $officialDate, ?int $userId): void
    {
        $draft->forceFill([
            'status' => InvoiceDraftStatus::Invoiced,
            'official_number' => $officialNumber,
            'official_date' => $officialDate,
            'invoiced_at' => now(),
            'invoiced_by_user_id' => $userId,
        ])->save();
    }
}
