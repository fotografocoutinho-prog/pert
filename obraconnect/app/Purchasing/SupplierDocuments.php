<?php

namespace App\Purchasing;

use App\Enums\CostCategory;
use App\Enums\PartnerKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentKind;
use App\Enums\SupplierDocumentStatus;
use App\Exceptions\ApiException;
use App\Models\CostEntry;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierDocument;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras dos documentos de fornecedores: preencher com o que o DocIntel leu, sugerir a compra,
 * avisar (duplicados, NIF de outra empresa, totais que não batem) e lançar ou desfazer o lançamento.
 *
 * Lançar uma fatura ou talão: com compra → a compra fica faturada pelo valor sem IVA; sem compra →
 * custo realizado na obra. Nota de crédito → custo negativo. Guia com compra → a compra fica recebida.
 */
final class SupplierDocuments
{
    /** @param  array<string, mixed>  $r  resultado do DocIntel */
    public static function applyReading(SupplierDocument $doc, array $r): void
    {
        $value = fn (string $path) => data_get($r, "{$path}.value");
        $text = function (string $path, int $max) use ($value): ?string {
            $v = $value($path);

            return is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        };
        $date = function (string $path) use ($value): ?string {
            $v = $value($path);
            if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return "{$m[1]}-{$m[2]}-{$m[3]}";
        };
        $cents = fn (string $path) => is_numeric($v = $value($path)) ? (int) round((float) $v * 100) : null;

        $reasons = array_values(array_filter((array) ($r['review_reasons'] ?? []), 'is_string'));
        if (data_get($r, 'invoice.cancelled') === true) {
            array_unshift($reasons, 'O código QR diz que o documento foi anulado pelo fornecedor.');
        }

        $doc->forceFill([
            'kind' => SupplierDocumentKind::fromReader(is_string($r['document_type'] ?? null) ? $r['document_type'] : null),
            'reader' => 'docintel',
            'reader_document_id' => isset($r['document_id']) ? mb_substr((string) $r['document_id'], 0, 64) : null,
            'confidence' => is_numeric($r['document_confidence'] ?? null) ? round(min(1, max(0, (float) $r['document_confidence'])), 4) : null,
            'review_reasons' => array_slice($reasons, 0, 20),
            'supplier_name' => $text('supplier.name', 200),
            'supplier_nif' => self::nif($value('supplier.tax_id')),
            'customer_nif' => self::nif($value('customer.tax_id')),
            'number' => $text('invoice.number', 60),
            'atcud' => $text('invoice.atcud', 100),
            'issue_date' => $date('invoice.date'),
            'due_date' => $date('invoice.due_date'),
            'order_reference' => $text('invoice.references.order_number', 60),
            'net_cents' => $cents('totals.net'),
            'vat_cents' => $cents('totals.tax'),
            'gross_cents' => $cents('totals.gross'),
            'lines' => collect((array) ($r['items'] ?? []))->take(300)->map(fn ($item) => [
                'code' => is_scalar($v = data_get($item, 'code.value')) ? mb_substr((string) $v, 0, 40) : null,
                'description' => is_scalar($v = data_get($item, 'description.value')) ? mb_substr((string) $v, 0, 500) : '',
                'quantity' => is_numeric($v = data_get($item, 'quantity.value')) ? (float) $v : null,
                'unit' => is_scalar($v = data_get($item, 'unit.value')) ? mb_substr((string) $v, 0, 20) : null,
                'unit_price' => is_numeric($v = data_get($item, 'unit_price.value')) ? (float) $v : null,
                'vat_rate' => is_numeric($v = data_get($item, 'tax_rate.value')) ? (float) $v : null,
                'total' => is_numeric($v = data_get($item, 'line_total.value')) ? (float) $v : null,
                'arithmetic_ok' => is_bool($v = data_get($item, 'arithmetic_ok')) ? $v : null,
            ])->values()->all(),
        ]);

        self::guessLinks($doc);
    }

    /** Fornecedor pelo NIF, compra pela referência da encomenda ou pelo valor, e a obra da compra. */
    public static function guessLinks(SupplierDocument $doc): void
    {
        if ($doc->partner_id === null && $doc->supplier_nif !== null) {
            $partner = Partner::query()->where('nif', $doc->supplier_nif)->whereNull('archived_at')->first();
            if ($partner) {
                $doc->partner_id = $partner->id;
                $doc->setRelation('partner', $partner);
                if ($partner->kind === PartnerKind::Subcontractor) {
                    $doc->category = CostCategory::Subcontract;
                }
            }
        }
        if ($doc->purchase_order_id !== null || $doc->partner_id === null || ! in_array($doc->kind, [SupplierDocumentKind::Invoice, SupplierDocumentKind::Receipt, SupplierDocumentKind::DeliveryNote], true)) {
            return;
        }

        $best = self::purchaseCandidates($doc)->filter(fn (array $c) => $c['strong'])->values();
        if ($best->count() === 1) {
            $doc->purchase_order_id = $best[0]['order']->id;
            $doc->project_id = $best[0]['order']->project_id;
            $doc->category = $best[0]['order']->category;
        } elseif ($doc->project_id === null) {
            $projects = self::purchaseCandidates($doc)->map(fn (array $c) => $c['order']->project_id)->unique();
            if ($projects->count() === 1) {
                $doc->project_id = $projects->first();
            }
        }
    }

    /**
     * Compras aprovadas ou recebidas do mesmo fornecedor (na obra do documento, se já tiver uma).
     * "Forte" = o número da encomenda bate com a referência lida, ou o valor sem IVA bate a 1 %.
     *
     * @return Collection<int, array{order: PurchaseOrder, strong: bool, reason: ?string}>
     */
    public static function purchaseCandidates(SupplierDocument $doc): Collection
    {
        if ($doc->partner_id === null) {
            return collect();
        }
        $reference = $doc->order_reference !== null ? preg_replace('/\D+/', '', $doc->order_reference) : '';
        $net = $doc->netCents();

        return PurchaseOrder::query()->with(['lines', 'project:id,code,name'])
            ->where('partner_id', $doc->partner_id)
            ->whereIn('status', [PurchaseStatus::Approved->value, PurchaseStatus::Received->value])
            ->when($doc->project_id, fn ($q) => $q->where('project_id', $doc->project_id))
            ->orderByDesc('id')->limit(50)->get()
            ->map(function (PurchaseOrder $o) use ($reference, $net): array {
                $total = $o->totalCents();
                $reason = match (true) {
                    $reference !== '' && (string) $o->number === ltrim($reference, '0') => 'o número da encomenda é o da referência lida',
                    $net !== null && $total > 0 && abs($net - $total) <= max(100, $total / 100) => 'o valor sem IVA é o da compra',
                    default => null,
                };

                return ['order' => $o, 'strong' => $reason !== null, 'reason' => $reason];
            })
            ->sortByDesc(fn (array $c) => $c['strong'])->values();
    }

    /** @return list<string> avisos para quem revê (não impedem o lançamento, exceto o duplicado lançado) */
    public static function warnings(SupplierDocument $doc, Tenant $tenant): array
    {
        $warnings = [];
        if ($doc->read_error) {
            $warnings[] = "Não foi possível ler automaticamente: {$doc->read_error} Preencha os dados à mão.";
        }
        foreach ((array) $doc->review_reasons as $reason) {
            $warnings[] = "Leitura a confirmar: {$reason}";
        }
        if ($other = self::duplicateOf($doc)) {
            $warnings[] = "Já existe {$other->kind->label()} {$other->number} deste fornecedor (documento n.º {$other->id}, {$other->status->label()}).";
        }
        $ownNif = preg_replace('/\D+/', '', (string) $tenant->nif);
        if ($doc->customer_nif && $ownNif !== '' && preg_replace('/\D+/', '', $doc->customer_nif) !== $ownNif) {
            $warnings[] = "O documento é dirigido ao NIF {$doc->customer_nif}, não ao da empresa ({$tenant->nif}).";
        }
        if ($doc->net_cents !== null && $doc->vat_cents !== null && $doc->gross_cents !== null && abs($doc->net_cents + $doc->vat_cents - $doc->gross_cents) > 1) {
            $warnings[] = 'O valor sem IVA mais o IVA não dá o total: confira os valores.';
        }
        if ($doc->partner?->kind === PartnerKind::Subcontractor && $doc->kind->isCost()) {
            $warnings[] = 'É um subempreiteiro: se o trabalho já entrou num auto de subempreitada, o custo já está contado — não lance também a fatura.';
        }
        if ($doc->partner_id === null && $doc->supplier_nif && Partner::query()->where('nif', $doc->supplier_nif)->whereNotNull('archived_at')->exists()) {
            $warnings[] = "O fornecedor com o NIF {$doc->supplier_nif} está arquivado.";
        }

        return $warnings;
    }

    /** Outro documento (não rejeitado) com o mesmo fornecedor, tipo e número. */
    public static function duplicateOf(SupplierDocument $doc): ?SupplierDocument
    {
        if (! $doc->number || (! $doc->supplier_nif && ! $doc->partner_id)) {
            return null;
        }

        return SupplierDocument::query()->whereKeyNot($doc->id)
            ->where('kind', $doc->kind->value)
            ->where('number', $doc->number)
            ->where('status', '!=', SupplierDocumentStatus::Rejected->value)
            ->where(fn ($q) => $q
                ->when($doc->supplier_nif, fn ($q) => $q->orWhere('supplier_nif', $doc->supplier_nif))
                ->when($doc->partner_id, fn ($q) => $q->orWhere('partner_id', $doc->partner_id)))
            ->orderByRaw("case when status = 'posted' then 0 else 1 end")
            ->first();
    }

    /**
     * Valida e lança (a autorização por obra fica no controlador). Devolve o que foi feito, para a auditoria.
     *
     * @return array{action: string, purchase_number?: int, cost_entry_id?: int}
     */
    public static function post(SupplierDocument $doc, int $userId): array
    {
        return DB::transaction(function () use ($doc, $userId): array {
            $doc = SupplierDocument::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status !== SupplierDocumentStatus::ToReview) {
                throw ApiException::conflict("O documento está «{$doc->status->label()}»: não pode ser lançado.", 'SUPPLIER_DOC_STATUS');
            }
            if ($doc->kind === SupplierDocumentKind::Other) {
                throw ApiException::unprocessable('Escolha o tipo de documento (fatura, nota de crédito, talão ou guia).', 'SUPPLIER_DOC_KIND');
            }
            if ($doc->project_id === null) {
                throw ApiException::unprocessable('Escolha a obra.', 'SUPPLIER_DOC_PROJECT');
            }
            $duplicate = self::duplicateOf($doc);
            if ($duplicate && $duplicate->status === SupplierDocumentStatus::Posted) {
                throw ApiException::conflict("{$duplicate->kind->label()} {$duplicate->number} deste fornecedor já foi lançada (documento n.º {$duplicate->id}).", 'SUPPLIER_DOC_DUPLICATE');
            }
            if ($doc->kind->isCost()) {
                if (! $doc->number || ! $doc->issue_date) {
                    throw ApiException::unprocessable('Falta o número ou a data do documento.', 'SUPPLIER_DOC_INCOMPLETE');
                }
                if (! $doc->netCents()) {
                    throw ApiException::unprocessable('Falta o valor sem IVA.', 'SUPPLIER_DOC_INCOMPLETE');
                }
            }

            $order = $doc->purchase_order_id ? PurchaseOrder::query()->lockForUpdate()->findOrFail($doc->purchase_order_id) : null;
            if ($order) {
                self::assertOrderFits($doc, $order);
                $doc->partner_id ??= $order->partner_id;
            }
            if ($doc->kind->isCost() && ! $order && ! $doc->partner_id && ! $doc->supplier_name) {
                throw ApiException::unprocessable('Indique o fornecedor.', 'SUPPLIER_DOC_INCOMPLETE');
            }

            $done = ['action' => 'archived'];
            if ($order && $doc->kind === SupplierDocumentKind::DeliveryNote) {
                if ($order->status === PurchaseStatus::Approved) {
                    $order->lines()->each(fn (PurchaseOrderLine $l) => $l->forceFill(['received_quantity' => $l->quantity])->save());
                    $order->forceFill(['status' => PurchaseStatus::Received, 'received_at' => now()])->save();
                }
                $done = ['action' => 'received', 'purchase_number' => $order->number];
            } elseif ($order) {
                $order->forceFill([
                    'status' => PurchaseStatus::Invoiced,
                    'invoice_number' => mb_substr((string) $doc->number, 0, 60),
                    'invoiced_on' => $doc->issue_date->toDateString(),
                    'invoiced_cents' => $doc->netCents(),
                ])->save();
                $done = ['action' => 'invoiced', 'purchase_number' => $order->number];
            } elseif ($doc->kind->isCost()) {
                $sign = $doc->kind === SupplierDocumentKind::CreditNote ? -1 : 1;
                $entry = new CostEntry([
                    'category' => $doc->category,
                    'kind' => 'actual',
                    'description' => mb_substr(($doc->kind === SupplierDocumentKind::CreditNote ? 'Nota de crédito ' : '').$doc->label(), 0, 255),
                    'amount_cents' => $sign * abs((int) $doc->netCents()),
                    'incurred_on' => $doc->issue_date->toDateString(),
                    'cost_center_id' => $doc->cost_center_id ?? $doc->project->cost_center_id,
                ]);
                $entry->project_id = $doc->project_id;
                $entry->created_by_user_id = $userId;
                $entry->save();
                $doc->cost_entry_id = $entry->id;
                $done = ['action' => 'cost_entry', 'cost_entry_id' => $entry->id];
            }

            $doc->forceFill(['status' => SupplierDocumentStatus::Posted, 'posted_at' => now(), 'posted_by_user_id' => $userId])->save();

            return $done;
        });
    }

    /** Desfaz o lançamento: apaga o custo ou volta a pôr a compra como estava antes da fatura. */
    public static function unpost(SupplierDocument $doc): void
    {
        DB::transaction(function () use ($doc): void {
            $doc = SupplierDocument::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status !== SupplierDocumentStatus::Posted) {
                throw ApiException::conflict('O documento não está lançado.', 'SUPPLIER_DOC_STATUS');
            }
            if ($doc->cost_entry_id) {
                $entry = CostEntry::query()->find($doc->cost_entry_id);
                $doc->forceFill(['cost_entry_id' => null])->save();
                $entry?->delete();
            }
            $order = $doc->purchase_order_id ? PurchaseOrder::query()->lockForUpdate()->find($doc->purchase_order_id) : null;
            if ($order && $doc->kind->isCost() && $order->status === PurchaseStatus::Invoiced && $order->invoice_number === $doc->number) {
                $order->forceFill([
                    'status' => $order->received_at ? PurchaseStatus::Received : PurchaseStatus::Approved,
                    'invoice_number' => null,
                    'invoiced_on' => null,
                    'invoiced_cents' => null,
                ])->save();
            }
            $doc->forceFill(['status' => SupplierDocumentStatus::ToReview, 'posted_at' => null, 'posted_by_user_id' => null])->save();
        });
    }

    private static function assertOrderFits(SupplierDocument $doc, PurchaseOrder $order): void
    {
        if ((int) $order->project_id !== (int) $doc->project_id) {
            throw ApiException::unprocessable('A compra escolhida é de outra obra.', 'SUPPLIER_DOC_PURCHASE');
        }
        if ($doc->partner_id && (int) $order->partner_id !== (int) $doc->partner_id) {
            throw ApiException::unprocessable('A compra escolhida é de outro fornecedor.', 'SUPPLIER_DOC_PURCHASE');
        }
        if ($doc->kind === SupplierDocumentKind::CreditNote) {
            throw ApiException::unprocessable('Uma nota de crédito não se lança numa compra: tire a compra e lance-a como custo (negativo).', 'SUPPLIER_DOC_PURCHASE');
        }
        if (! in_array($order->status, [PurchaseStatus::Approved, PurchaseStatus::Received], true)) {
            throw ApiException::conflict("A compra n.º {$order->number} está «{$order->status->label()}»: só se lança em compras aprovadas ou recebidas.", 'SUPPLIER_DOC_PURCHASE');
        }
    }

    /** "PT 505 767 252" → "505767252"; outros países ficam com as letras. */
    public static function nif(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $nif = strtoupper(preg_replace('/[\s.\-]+/', '', (string) $value));
        if (str_starts_with($nif, 'PT') && preg_match('/^PT\d{9}$/', $nif)) {
            $nif = substr($nif, 2);
        }

        return $nif !== '' ? mb_substr($nif, 0, 20) : null;
    }
}
