<?php

namespace App\Invoicing;

use App\Models\InvoiceDraft;
use App\Models\InvoiceDraftLine;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exportação de pré-faturas para importar num programa de faturação certificado: uma linha por linha
 * de pré-fatura, com os dados do cliente repetidos (é o formato que os importadores por folha de cálculo
 * esperam). Excel com números a sério; CSV com ";" e vírgula decimal (Excel em português); JSON com os
 * documentos e as suas linhas, para importações por API.
 */
final class InvoiceExport
{
    public const COLUMNS = [
        'Documento', 'Estado', 'Data', 'Vencimento', 'Cliente', 'NIF', 'Morada', 'Código postal', 'Localidade', 'País', 'Email',
        'Obra', 'Referência', 'Linha', 'Código', 'Descrição', 'Unidade', 'Quantidade', 'Preço unitário', 'Desconto %',
        'Taxa IVA %', 'Código isenção', 'Motivo isenção', 'Valor sem IVA', 'IVA', 'Valor com IVA', 'Notas',
    ];

    /**
     * @param  Collection<int, InvoiceDraft>  $drafts  com lines e project carregados
     * @return list<list<string|int|float|null>> valores em euros (float) e datas AAAA-MM-DD
     */
    public static function rows(Collection $drafts): array
    {
        $rows = [];
        foreach ($drafts as $draft) {
            foreach ($draft->lines as $i => $line) {
                /** @var InvoiceDraftLine $line */
                [$net, $vat] = $line->amounts();
                $rows[] = [
                    $draft->code() ?? 'Rascunho #'.$draft->id,
                    $draft->status->label(),
                    $draft->issue_date?->toDateString(),
                    $draft->due_date?->toDateString(),
                    $draft->client_name,
                    $draft->client_nif,
                    $draft->client_address,
                    $draft->client_postal_code,
                    $draft->client_city,
                    $draft->client_country,
                    $draft->client_email,
                    $draft->project?->code,
                    $draft->reference,
                    $i + 1,
                    $line->code,
                    $line->description,
                    $line->unit,
                    (float) $line->quantity,
                    $line->unit_price_cents / 100,
                    (float) $line->discount_pct,
                    (float) $line->vat_rate,
                    (float) $line->vat_rate == 0.0 ? $draft->vat_exemption_code : null,
                    (float) $line->vat_rate == 0.0 ? $draft->vat_exemption_reason : null,
                    $net / 100,
                    $vat / 100,
                    ($net + $vat) / 100,
                    $draft->notes,
                ];
            }
        }

        return $rows;
    }

    /** @param  Collection<int, InvoiceDraft>  $drafts */
    public static function xlsx(Collection $drafts, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::COLUMNS));
        foreach (self::rows($drafts) as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
    }

    /**
     * CSV para o Excel em português: UTF-8 com BOM, ";" e vírgula decimal.
     *
     * @param  Collection<int, InvoiceDraft>  $drafts
     * @param  resource  $out
     */
    public static function csv(Collection $drafts, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ';', '"', '');
        foreach (self::rows($drafts) as $row) {
            fputcsv($out, array_map(fn ($v) => is_float($v) ? self::decimal($v) : $v, $row), ';', '"', '');
        }
    }

    /**
     * @param  Collection<int, InvoiceDraft>  $drafts
     * @return list<array<string, mixed>>
     */
    public static function json(Collection $drafts): array
    {
        return $drafts->map(function (InvoiceDraft $d): array {
            $totals = $d->totals();

            return [
                'document' => $d->code(),
                'status' => $d->status->value,
                'fiscal' => false,
                'issue_date' => $d->issue_date?->toDateString(),
                'due_date' => $d->due_date?->toDateString(),
                'customer' => [
                    'name' => $d->client_name, 'nif' => $d->client_nif, 'address' => $d->client_address,
                    'postal_code' => $d->client_postal_code, 'city' => $d->client_city, 'country' => $d->client_country, 'email' => $d->client_email,
                ],
                'project' => $d->project?->code,
                'reference' => $d->reference,
                'vat_exemption' => ['code' => $d->vat_exemption_code, 'reason' => $d->vat_exemption_reason],
                'notes' => $d->notes,
                'lines' => $d->lines->values()->map(fn (InvoiceDraftLine $l) => [
                    'code' => $l->code, 'description' => $l->description, 'unit' => $l->unit,
                    'quantity' => (float) $l->quantity, 'unit_price' => $l->unit_price_cents / 100,
                    'discount_pct' => (float) $l->discount_pct, 'vat_rate' => (float) $l->vat_rate,
                    'net' => $totals['lines'][$l->id]['net_cents'] / 100, 'vat' => $totals['lines'][$l->id]['vat_cents'] / 100,
                ])->all(),
                'totals' => ['net' => $totals['net_cents'] / 100, 'vat' => $totals['vat_cents'] / 100, 'total' => $totals['total_cents'] / 100],
                'official_invoice' => $d->official_number ? ['number' => $d->official_number, 'date' => $d->official_date?->toDateString()] : null,
            ];
        })->values()->all();
    }

    private static function decimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',');

        return $text === '-0' ? '0' : $text;
    }
}
