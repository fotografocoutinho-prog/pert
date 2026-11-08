<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\InvoiceDraftStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Invoicing\InvoiceDraftPdf;
use App\Invoicing\InvoiceDrafts;
use App\Invoicing\InvoiceExport;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\InvoiceDraft;
use App\Models\InvoiceDraftLine;
use App\Models\Project;
use App\Rules\ValidPortugueseNif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pré-faturas (documentos internos sem valor fiscal) para exportar para o programa de faturação
 * certificado. Consultar: invoicing.view; preparar, exportar e registar a fatura oficial: invoicing.manage.
 */
class InvoiceDraftController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(['invoicing.view', 'invoicing.manage']);
        $filters = $this->filters($request);

        return response()->json(['data' => $this->query($filters)->limit(500)->get()->map(fn (InvoiceDraft $d) => $this->summary($d))->values()]);
    }

    /** A partir de um auto aprovado (certificate_id) ou à mão. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('invoicing.manage');
        if ($request->filled('certificate_id')) {
            $id = $request->validate(['certificate_id' => ['integer', Rule::exists('certificates', 'id')->where('tenant_id', $this->tenantContext()->id())]])['certificate_id'];
            $draft = InvoiceDrafts::fromCertificate(Certificate::query()->findOrFail($id), $request->user()->id);
        } else {
            $data = $this->validated($request, null);
            $draft = DB::transaction(function () use ($data, $request): InvoiceDraft {
                $draft = new InvoiceDraft($this->headerWithClient($data));
                $draft->created_by_user_id = $request->user()->id;
                $draft->save();
                $this->syncLines($draft, $data['lines'] ?? []);

                return $draft;
            });
        }
        $this->auditor->record('invoice_draft.created', $draft, ['certificate_id' => $draft->certificate_id, 'client' => $draft->client_name]);

        return response()->json(['data' => $this->present($draft)], 201);
    }

    public function show(InvoiceDraft $invoiceDraft): JsonResponse
    {
        $this->authorizeAny(['invoicing.view', 'invoicing.manage']);

        return response()->json(['data' => $this->present($invoiceDraft)]);
    }

    public function update(Request $request, InvoiceDraft $invoiceDraft): JsonResponse
    {
        $this->authorize('invoicing.manage');
        $this->assertStatus($invoiceDraft, [InvoiceDraftStatus::Draft], 'alterar');
        $data = $this->validated($request, $invoiceDraft);
        DB::transaction(function () use ($invoiceDraft, $data): void {
            $invoiceDraft->fill($this->headerWithClient($data, $invoiceDraft))->save();
            if (array_key_exists('lines', $data)) {
                $this->syncLines($invoiceDraft, $data['lines'] ?? []);
            }
        });

        return response()->json(['data' => $this->present($invoiceDraft->refresh())]);
    }

    public function destroy(InvoiceDraft $invoiceDraft): Response
    {
        $this->authorize('invoicing.manage');
        $this->assertStatus($invoiceDraft, [InvoiceDraftStatus::Draft], 'apagar');
        if ($invoiceDraft->number !== null) {
            throw ApiException::conflict('Esta pré-fatura já teve número: anule-a em vez de a apagar.', 'INVOICE_NUMBERED');
        }
        $invoiceDraft->delete();

        return response()->noContent();
    }

    /** ready (rascunho → pronta), reopen (pronta → rascunho), invoice (pronta → faturada), cancel. */
    public function transition(Request $request, InvoiceDraft $invoiceDraft, string $action): JsonResponse
    {
        $this->authorize('invoicing.manage');
        $draft = $invoiceDraft;
        switch ($action) {
            case 'ready':
                $this->assertStatus($draft, [InvoiceDraftStatus::Draft], 'dar como pronta');
                $date = $request->validate(['issue_date' => ['nullable', 'date_format:Y-m-d']], [], ['issue_date' => 'data'])['issue_date'] ?? null;
                InvoiceDrafts::markReady($draft, $date);
                break;
            case 'reopen':
                $this->assertStatus($draft, [InvoiceDraftStatus::Ready], 'reabrir');
                $draft->forceFill(['status' => InvoiceDraftStatus::Draft, 'ready_at' => null])->save();
                break;
            case 'invoice':
                $this->assertStatus($draft, [InvoiceDraftStatus::Ready], 'registar a fatura');
                $data = $request->validate([
                    'official_number' => ['required', 'string', 'max:60'],
                    'official_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Lisbon')->toDateString()],
                ], [], ['official_number' => 'n.º da fatura', 'official_date' => 'data da fatura']);
                InvoiceDrafts::markInvoiced($draft, trim($data['official_number']), $data['official_date'], $request->user()->id);
                break;
            case 'cancel':
                $this->assertStatus($draft, [InvoiceDraftStatus::Draft, InvoiceDraftStatus::Ready], 'anular');
                $draft->forceFill(['status' => InvoiceDraftStatus::Cancelled])->save();
                break;
            default:
                abort(404);
        }
        $this->auditor->record("invoice_draft.{$action}", $draft, array_filter(['code' => $draft->code(), 'official_number' => $draft->official_number]));

        return response()->json(['data' => $this->present($draft->refresh())]);
    }

    /** Folha para importar no programa de faturação (por omissão, as prontas a faturar). */
    public function export(Request $request): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $this->authorizeAny(['invoicing.view', 'invoicing.manage']);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv', 'json'])]])['format'] ?? 'xlsx';
        $filters = $this->filters($request) + ['status' => null];
        if (empty($filters['ids']) && $filters['status'] === null) {
            $filters['status'] = InvoiceDraftStatus::Ready->value;
        }
        $drafts = $this->query($filters)->with('lines')->get()->sortBy(fn (InvoiceDraft $d) => [$d->series_year ?? 9999, $d->number ?? PHP_INT_MAX, $d->id])->values();
        $this->auditor->record('invoice_draft.exported', null, ['count' => $drafts->count(), 'format' => $format]);
        $name = 'pre-faturas-'.now('Europe/Lisbon')->format('Ymd-Hi');

        if ($format === 'json') {
            return response()->json(['data' => InvoiceExport::json($drafts)], 200, ['Content-Disposition' => "attachment; filename=\"{$name}.json\""], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($drafts): void {
                $out = fopen('php://output', 'w');
                InvoiceExport::csv($drafts, $out);
                fclose($out);
            }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = tempnam(sys_get_temp_dir(), 'pf').'.xlsx';
        InvoiceExport::xlsx($drafts, $path);

        return response()->download($path, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function pdf(InvoiceDraft $invoiceDraft, InvoiceDraftPdf $pdf): Response
    {
        $this->authorizeAny(['invoicing.view', 'invoicing.manage']);

        return response($pdf->render($this->tenantContext()->tenantOrFail(), $invoiceDraft), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($invoiceDraft).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceDraftStatus::class)],
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);
    }

    /** @param  array<string, mixed>  $f */
    private function query(array $f)
    {
        return InvoiceDraft::query()->with(['project:id,code,name', 'lines'])
            ->when(! empty($f['ids']), fn ($q) => $q->whereKey($f['ids']))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['project_id'] ?? null, fn ($q, $p) => $q->where('project_id', $p))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('client_name', 'like', "%{$s}%")->orWhere('client_nif', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")->orWhere('official_number', 'like', "%{$s}%")))
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?InvoiceDraft $draft): array
    {
        $tenant = $this->tenantContext()->id();
        $sometimes = $draft ? 'sometimes' : 'nullable';
        $country = strtoupper((string) ($request->input('client_country') ?? $draft?->client_country ?? 'PT'));

        return $request->validate([
            'project_id' => [$sometimes, 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenant)],
            'client_id' => [$sometimes, 'nullable', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenant)],
            'client_name' => [$draft ? 'sometimes' : 'required_without_all:client_id,project_id', 'nullable', 'string', 'max:200'],
            'client_nif' => ['sometimes', 'nullable', 'string', 'max:20', ...($country === 'PT' ? [new ValidPortugueseNif] : [])],
            'client_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'client_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'client_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'client_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'vat_exemption_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vat_exemption_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.code' => ['nullable', 'string', 'max:30'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.unit' => ['required', 'string', 'max:20'],
            'lines.*.quantity' => ['required', 'numeric', 'not_in:0', 'between:-9999999,9999999'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.vat_rate' => ['required', 'numeric', 'between:0,100'],
        ], [], [
            'client_name' => 'cliente', 'client_nif' => 'NIF', 'issue_date' => 'data', 'due_date' => 'vencimento',
            'lines.*.description' => 'descrição', 'lines.*.quantity' => 'quantidade', 'lines.*.unit_price_cents' => 'preço', 'lines.*.vat_rate' => 'taxa de IVA',
        ]);
    }

    /**
     * Com um cliente escolhido (e sem os campos escritos), copia os dados dele.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerWithClient(array $data, ?InvoiceDraft $draft = null): array
    {
        $header = collect($data)->except('lines')->all();
        $clientChanged = array_key_exists('client_id', $header) && $header['client_id'] !== null && (int) $header['client_id'] !== (int) $draft?->client_id;
        if ($clientChanged && blank($header['client_name'] ?? null)) {
            $header = array_merge($header, InvoiceDrafts::clientFields(Client::query()->find($header['client_id'])));
        }
        if (! $draft && ! isset($header['client_name']) && isset($header['project_id'])) {
            $project = Project::query()->with('client')->find($header['project_id']);
            $header = InvoiceDrafts::clientFields($project?->client, $project) + $header;
        }
        if (isset($header['client_country'])) {
            $header['client_country'] = strtoupper($header['client_country']);
        }

        return $header;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(InvoiceDraft $draft, array $lines): void
    {
        $draft->lines()->delete();
        foreach (array_values($lines) as $i => $line) {
            $draft->lines()->create([
                'code' => $line['code'] ?? null,
                'description' => $line['description'],
                'unit' => $line['unit'],
                'quantity' => self::decimal($line['quantity']) ?? 0,
                'unit_price_cents' => (int) $line['unit_price_cents'],
                'discount_pct' => self::decimal($line['discount_pct'] ?? 0) ?? 0,
                'vat_rate' => self::decimal($line['vat_rate']) ?? 0,
                'sort_order' => $i,
            ]);
        }
    }

    /** @param  list<InvoiceDraftStatus>  $allowed */
    private function assertStatus(InvoiceDraft $draft, array $allowed, string $doing): void
    {
        if (! in_array($draft->status, $allowed, true)) {
            throw ApiException::conflict("A pré-fatura está «{$draft->status->label()}»: não é possível {$doing}.", 'INVOICE_STATUS');
        }
    }

    /** @return array<string, mixed> */
    private function summary(InvoiceDraft $d): array
    {
        $totals = $d->totals();

        return [
            'id' => $d->id,
            'code' => $d->code(),
            'status' => $d->status->value,
            'status_label' => $d->status->label(),
            'issue_date' => $d->issue_date?->toDateString(),
            'due_date' => $d->due_date?->toDateString(),
            'client_name' => $d->client_name,
            'client_nif' => $d->client_nif,
            'project' => $d->project ? ['id' => $d->project->id, 'code' => $d->project->code, 'name' => $d->project->name] : null,
            'reference' => $d->reference,
            'certificate_id' => $d->certificate_id,
            'net_cents' => $totals['net_cents'],
            'vat_cents' => $totals['vat_cents'],
            'total_cents' => $totals['total_cents'],
            'official_number' => $d->official_number,
            'official_date' => $d->official_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(InvoiceDraft $d): array
    {
        $d->load(['project:id,code,name', 'lines', 'createdBy:id,name', 'invoicedBy:id,name', 'certificate:id,project_id,number']);
        $totals = $d->totals();
        $manage = Gate::allows('invoicing.manage');

        return $this->summary($d) + [
            'client_id' => $d->client_id,
            'project_id' => $d->project_id,
            'client_address' => $d->client_address,
            'client_postal_code' => $d->client_postal_code,
            'client_city' => $d->client_city,
            'client_country' => $d->client_country,
            'client_email' => $d->client_email,
            'vat_exemption_code' => $d->vat_exemption_code,
            'vat_exemption_reason' => $d->vat_exemption_reason,
            'notes' => $d->notes,
            'certificate' => $d->certificate ? ['id' => $d->certificate->id, 'project_id' => $d->certificate->project_id, 'number' => $d->certificate->number] : null,
            'by_rate' => $totals['by_rate'],
            'lines' => $d->lines->map(fn (InvoiceDraftLine $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'description' => $l->description,
                'unit' => $l->unit,
                'quantity' => (float) $l->quantity,
                'unit_price_cents' => $l->unit_price_cents,
                'discount_pct' => (float) $l->discount_pct,
                'vat_rate' => (float) $l->vat_rate,
                'net_cents' => $totals['lines'][$l->id]['net_cents'],
                'vat_cents' => $totals['lines'][$l->id]['vat_cents'],
            ])->values()->all(),
            'created_by' => $d->createdBy?->name,
            'invoiced_by' => $d->invoicedBy?->name,
            'ready_at' => $d->ready_at?->toIso8601String(),
            'invoiced_at' => $d->invoiced_at?->toIso8601String(),
            'can' => [
                'edit' => $manage && $d->status === InvoiceDraftStatus::Draft,
                'ready' => $manage && $d->status === InvoiceDraftStatus::Draft,
                'reopen' => $manage && $d->status === InvoiceDraftStatus::Ready,
                'invoice' => $manage && $d->status === InvoiceDraftStatus::Ready,
                'cancel' => $manage && in_array($d->status, [InvoiceDraftStatus::Draft, InvoiceDraftStatus::Ready], true),
                'delete' => $manage && $d->status === InvoiceDraftStatus::Draft && $d->number === null,
            ],
        ];
    }
}
