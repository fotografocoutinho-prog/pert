<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Billing\StorageUsage;
use App\DocIntel\DocIntelClient;
use App\Enums\CostCategory;
use App\Enums\SupplierDocumentKind;
use App\Enums\SupplierDocumentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Jobs\ReadSupplierDocument;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\SupplierDocument;
use App\Purchasing\SupplierDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Faturas, notas de crédito, talões e guias de fornecedores. A caixa da empresa (todos os documentos,
 * também os que ainda não têm obra) é de quem tem supplier_docs.manage; quem gere compras ou custos
 * numa obra vê e trata os documentos dessa obra. Lançar numa compra exige purchases.manage na obra;
 * lançar como custo, costs.manage.
 */
class SupplierDocumentController extends Controller
{
    use GuardsProjectWrites;

    private const PROJECT_PERMISSIONS = ['purchases.manage', 'costs.manage'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly DocIntelClient $reader,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SupplierDocumentStatus::class)],
            'project_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $project = isset($filters['project_id']) ? Project::query()->findOrFail($filters['project_id']) : null;
        if (! Gate::allows('supplier_docs.manage')) {
            abort_unless($project && Gate::any(self::PROJECT_PERMISSIONS, $project), 403);
        }

        $base = SupplierDocument::query()->when($project, fn ($q) => $q->where('project_id', $project->id));
        $counts = (clone $base)->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $docs = (clone $base)->with(['partner:id,name', 'project:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, string $term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->where('supplier_name', 'like', $like)->orWhere('supplier_nif', 'like', $like)
                    ->orWhere('number', 'like', $like)->orWhere('original_name', 'like', $like));
            })
            ->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'data' => $docs->map(fn (SupplierDocument $d) => $this->summary($d))->values(),
            'meta' => [
                'counts' => collect(SupplierDocumentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'reader_enabled' => $this->reader->enabled(),
                'can_upload' => Gate::allows('supplier_docs.manage') || ($project && Gate::any(self::PROJECT_PERMISSIONS, $project) && ! $project->isArchived()),
            ],
        ]);
    }

    public function store(Request $request, PlanLimits $limits, StorageUsage $usage): JsonResponse
    {
        $tenant = $this->tenantContext()->tenantOrFail();
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png,webp', 'max:'.(config('obraconnect.docintel.max_mb') * 1024)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenant->id)],
        ], [
            'file.extensions' => 'Envie a fatura ou a guia em PDF ou em fotografia (JPG, PNG, WebP).',
            'file.max' => 'O ficheiro tem mais de '.config('obraconnect.docintel.max_mb').' MB.',
        ], ['file' => 'ficheiro', 'project_id' => 'obra']);

        $project = isset($data['project_id']) ? Project::query()->findOrFail($data['project_id']) : null;
        if ($project) {
            abort_unless(Gate::allows('supplier_docs.manage') || Gate::any(self::PROJECT_PERMISSIONS, $project), 403);
            $this->assertProjectOpen($project);
        } else {
            $this->authorize('supplier_docs.manage');
        }

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, SupplierDocument::MIME_TYPES, true)) {
            throw ApiException::unprocessable('O conteúdo do ficheiro não é PDF nem fotografia.', 'SUPPLIER_DOC_FILE');
        }
        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = SupplierDocument::query()->where('sha256', $sha)->first()) {
            throw new ApiException("Este ficheiro já foi carregado (documento n.º {$existing->id}).", 'SUPPLIER_DOC_DUPLICATE_FILE', 409, ['id' => $existing->id]);
        }
        $limits->assertCanAdd($tenant, 'storage_mb', (int) ceil($file->getSize() / 1048576));

        $reading = $this->reader->enabled();
        $doc = DB::transaction(function () use ($file, $mime, $sha, $project, $reading, $request): SupplierDocument {
            $doc = new SupplierDocument(['project_id' => $project?->id]);
            $doc->forceFill([
                'status' => $reading ? SupplierDocumentStatus::Reading : SupplierDocumentStatus::ToReview,
                'reader' => $reading ? 'docintel' : null,
                'disk' => config('obraconnect.files.disk'),
                'path' => '',
                'original_name' => Str::limit($file->getClientOriginalName() ?: 'documento', 250, ''),
                'mime' => $mime,
                'size_bytes' => $file->getSize(),
                'sha256' => $sha,
                'category' => CostCategory::Material,
                'cost_center_id' => $project?->cost_center_id,
                'created_by_user_id' => $request->user()->id,
            ])->save();
            $extension = strtolower($file->getClientOriginalExtension()) ?: ($mime === 'application/pdf' ? 'pdf' : 'jpg');
            $doc->forceFill(['path' => Storage::disk($doc->disk)->putFileAs($doc->directory(), $file, 'original-'.Str::lower(Str::random(8)).".{$extension}")])->save();

            return $doc;
        });
        $usage->add($tenant, $doc->size_bytes);
        $this->auditor->record('supplier_document.uploaded', $doc, ['file' => $doc->original_name, 'project' => $project?->code]);

        if ($reading) {
            ReadSupplierDocument::dispatch($doc->id);
        }

        return response()->json(['data' => $this->present($doc->refresh())], 201);
    }

    public function show(SupplierDocument $supplierDocument): JsonResponse
    {
        $this->authorizeDocument($supplierDocument);

        return response()->json(['data' => $this->present($supplierDocument)]);
    }

    public function update(Request $request, SupplierDocument $supplierDocument): JsonResponse
    {
        $doc = $supplierDocument;
        $this->authorizeDocument($doc);
        $this->assertDocumentOpen($doc);
        if (! $doc->isEditable()) {
            throw ApiException::conflict("O documento está «{$doc->status->label()}»: não se altera.", 'SUPPLIER_DOC_STATUS');
        }
        $tenant = $this->tenantContext()->id();
        $money = ['sometimes', 'nullable', 'integer', 'between:-100000000000,100000000000'];
        $data = $request->validate([
            'kind' => ['sometimes', Rule::enum(SupplierDocumentKind::class)],
            'category' => ['sometimes', Rule::enum(CostCategory::class)],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenant)],
            'partner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('partners', 'id')->where('tenant_id', $tenant)],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenant)],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('archived_at')],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'supplier_nif' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer_nif' => ['sometimes', 'nullable', 'string', 'max:20'],
            'number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'atcud' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'order_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'net_cents' => $money,
            'vat_cents' => $money,
            'gross_cents' => $money,
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], [
            'kind' => 'tipo', 'category' => 'natureza', 'project_id' => 'obra', 'partner_id' => 'fornecedor', 'purchase_order_id' => 'compra',
            'cost_center_id' => 'centro de custo', 'supplier_name' => 'fornecedor', 'supplier_nif' => 'NIF do fornecedor', 'customer_nif' => 'NIF do cliente',
            'number' => 'número', 'issue_date' => 'data', 'due_date' => 'vencimento', 'net_cents' => 'valor sem IVA', 'vat_cents' => 'IVA', 'gross_cents' => 'total',
        ]);
        foreach (['supplier_nif', 'customer_nif'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = SupplierDocuments::nif($data[$key]);
            }
        }

        $doc->fill($data);
        if (! empty($data['purchase_order_id'])) {
            $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
            if (! array_key_exists('project_id', $data) || $data['project_id'] === null) {
                $doc->project_id = $order->project_id;
            } elseif ((int) $order->project_id !== (int) $doc->project_id) {
                throw ApiException::unprocessable('A compra escolhida é de outra obra.', 'SUPPLIER_DOC_PURCHASE');
            }
            $doc->partner_id ??= $order->partner_id;
        } elseif ($doc->isDirty('project_id') && $doc->purchase_order_id && ! array_key_exists('purchase_order_id', $data)) {
            // Mudou de obra: a compra da obra anterior deixa de servir.
            $doc->purchase_order_id = null;
        }
        if ($doc->isDirty('project_id') && $doc->project_id) {
            $project = Project::query()->findOrFail($doc->project_id);
            // Quem só trata documentos da obra não os pode passar para uma obra onde não tem acesso.
            abort_unless(Gate::allows('supplier_docs.manage') || Gate::any(self::PROJECT_PERMISSIONS, $project), 403);
            if (! array_key_exists('cost_center_id', $data)) {
                $doc->cost_center_id = $project->cost_center_id;
            }
        }
        if ($doc->isDirty('project_id') && $doc->project_id === null) {
            $this->authorize('supplier_docs.manage');
        }
        $doc->save();
        $this->auditor->record('supplier_document.updated', $doc, ['changes' => array_keys($data)]);

        return response()->json(['data' => $this->present($doc->refresh())]);
    }

    /** post, unpost, reject, reopen, read. */
    public function transition(Request $request, SupplierDocument $supplierDocument, string $action): JsonResponse
    {
        $doc = $supplierDocument;
        $this->authorizeDocument($doc);
        $this->assertDocumentOpen($doc);

        switch ($action) {
            case 'post':
                $this->authorizePosting($doc);
                $done = SupplierDocuments::post($doc, $request->user()->id);
                $this->auditor->record('supplier_document.posted', $doc, $done + ['document' => $doc->label(), 'project' => $doc->project?->code]);
                break;
            case 'unpost':
                $this->authorizePosting($doc);
                SupplierDocuments::unpost($doc);
                $this->auditor->record('supplier_document.unposted', $doc, ['document' => $doc->label(), 'project' => $doc->project?->code]);
                break;
            case 'reject':
                $this->assertStatus($doc, [SupplierDocumentStatus::ToReview], 'rejeitar');
                $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
                $doc->forceFill([
                    'status' => SupplierDocumentStatus::Rejected,
                    'notes' => $reason ? trim(($doc->notes ? $doc->notes."\n" : '').'Rejeitado: '.$reason) : $doc->notes,
                ])->save();
                $this->auditor->record('supplier_document.rejected', $doc, ['document' => $doc->label(), 'reason' => $reason]);
                break;
            case 'reopen':
                $this->assertStatus($doc, [SupplierDocumentStatus::Rejected], 'reabrir');
                $doc->forceFill(['status' => SupplierDocumentStatus::ToReview])->save();
                $this->auditor->record('supplier_document.reopened', $doc, ['document' => $doc->label()]);
                break;
            case 'read':
                if (! $this->reader->enabled()) {
                    throw ApiException::conflict('A leitura automática (DocIntel) não está ligada nesta instalação.', 'SUPPLIER_DOC_READER_OFF');
                }
                if (! $this->canRead($doc)) {
                    throw ApiException::conflict("O documento está «{$doc->status->label()}»: não se lê de novo.", 'SUPPLIER_DOC_STATUS');
                }
                $doc->forceFill(['status' => SupplierDocumentStatus::Reading, 'reader' => 'docintel', 'read_error' => null])->save();
                ReadSupplierDocument::dispatch($doc->id);
                $this->auditor->record('supplier_document.read', $doc, ['document' => $doc->label()]);
                break;
            default:
                abort(404);
        }

        return response()->json(['data' => $this->present($doc->refresh())]);
    }

    public function destroy(SupplierDocument $supplierDocument, StorageUsage $usage): Response
    {
        $doc = $supplierDocument;
        $this->authorizeDocument($doc);
        $this->assertDocumentOpen($doc);
        $this->assertStatus($doc, [SupplierDocumentStatus::ToReview, SupplierDocumentStatus::Rejected], 'apagar (desfaça o lançamento primeiro)');

        Storage::disk($doc->disk)->deleteDirectory($doc->directory());
        $doc->delete();
        $usage->add($this->tenantContext()->tenantOrFail(), -$doc->size_bytes);
        $this->auditor->record('supplier_document.deleted', null, ['document' => $doc->label(), 'file' => $doc->original_name]);

        return response()->noContent();
    }

    /** O original, no browser (só PDF e imagens entram, por isso abre sempre inline). */
    public function file(SupplierDocument $supplierDocument): StreamedResponse
    {
        $this->authorizeDocument($supplierDocument);
        $disk = Storage::disk($supplierDocument->disk);
        abort_unless($disk->exists($supplierDocument->path), 404);

        return $disk->response($supplierDocument->path, $supplierDocument->original_name, [
            'Content-Type' => $supplierDocument->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Aparece ao lado dos dados, na página de revisão (SecurityHeaders só deixa a mesma origem).
            'X-Frame-Options' => 'SAMEORIGIN',
        ], 'inline');
    }

    private function authorizeDocument(SupplierDocument $doc): void
    {
        if (Gate::allows('supplier_docs.manage')) {
            return;
        }
        abort_unless($doc->project && Gate::any(self::PROJECT_PERMISSIONS, $doc->project), 403);
    }

    /** Documento de uma obra arquivada: só se consulta. */
    private function assertDocumentOpen(SupplierDocument $doc): void
    {
        if ($doc->project !== null) {
            $this->assertProjectOpen($doc->project);
        }
    }

    /** Lançar numa compra (ou guia): purchases.manage na obra; como custo: costs.manage na obra. */
    private function authorizePosting(SupplierDocument $doc): void
    {
        if ($doc->project === null) {
            throw ApiException::unprocessable('Escolha a obra.', 'SUPPLIER_DOC_PROJECT');
        }
        $this->assertProjectOpen($doc->project);
        $needsPurchase = $doc->purchase_order_id !== null || $doc->kind === SupplierDocumentKind::DeliveryNote;
        $this->authorize($needsPurchase ? 'purchases.manage' : 'costs.manage', $doc->project);
    }

    /** @param  list<SupplierDocumentStatus>  $allowed */
    private function assertStatus(SupplierDocument $doc, array $allowed, string $doing): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            throw ApiException::conflict("O documento está «{$doc->status->label()}»: não é possível {$doing}.", 'SUPPLIER_DOC_STATUS');
        }
    }

    /** Por rever, ou "a ler" há mais de 10 minutos (a fila parou ou o trabalho morreu). */
    private function canRead(SupplierDocument $doc): bool
    {
        return $doc->status === SupplierDocumentStatus::ToReview
            || ($doc->status === SupplierDocumentStatus::Reading && $doc->updated_at?->lt(now()->subMinutes(10)));
    }

    /** @return array<string, mixed> */
    private function summary(SupplierDocument $d): array
    {
        return [
            'id' => $d->id,
            'kind' => $d->kind->value,
            'kind_label' => $d->kind->label(),
            'status' => $d->status->value,
            'status_label' => $d->status->label(),
            'supplier' => $d->partner?->name ?? $d->supplier_name,
            'supplier_nif' => $d->supplier_nif,
            'number' => $d->number,
            'issue_date' => $d->issue_date?->toDateString(),
            'net_cents' => $d->netCents(),
            'gross_cents' => $d->gross_cents,
            'project' => $d->project ? ['id' => $d->project->id, 'code' => $d->project->code, 'name' => $d->project->name] : null,
            'reader' => $d->reader,
            'confidence' => $d->confidence,
            'attention' => (bool) $d->read_error || ! empty($d->review_reasons),
            'original_name' => $d->original_name,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(SupplierDocument $d): array
    {
        $d->loadMissing(['partner', 'project', 'purchaseOrder.lines', 'costCenter:id,code,name', 'createdBy:id,name', 'postedBy:id,name']);
        $tenant = $this->tenantContext()->tenantOrFail();
        $open = $d->project === null || ! $d->project->isArchived();
        $canPost = $d->project !== null && $open && Gate::allows(
            $d->purchase_order_id !== null || $d->kind === SupplierDocumentKind::DeliveryNote ? 'purchases.manage' : 'costs.manage',
            $d->project,
        );
        $editable = $d->isEditable() && $open;
        $order = $d->purchaseOrder;

        return array_merge($this->summary($d), [
            // No formulário vai o valor escrito; net_effective_cents é o que conta (total − IVA, se faltar).
            'net_cents' => $d->net_cents,
            'net_effective_cents' => $d->netCents(),
            'category' => $d->category->value,
            'category_label' => $d->category->label(),
            'file' => ['name' => $d->original_name, 'mime' => $d->mime, 'size_bytes' => $d->size_bytes, 'is_pdf' => $d->mime === 'application/pdf'],
            'reader_document_id' => $d->reader_document_id,
            'review_reasons' => $d->review_reasons ?? [],
            'read_error' => $d->read_error,
            'read_at' => $d->read_at?->toIso8601String(),
            'lines' => $d->lines ?? [],
            'supplier_name' => $d->supplier_name,
            'customer_nif' => $d->customer_nif,
            'atcud' => $d->atcud,
            'due_date' => $d->due_date?->toDateString(),
            'order_reference' => $d->order_reference,
            'vat_cents' => $d->vat_cents,
            'notes' => $d->notes,
            'project_id' => $d->project_id,
            'partner_id' => $d->partner_id,
            'partner' => $d->partner ? ['id' => $d->partner->id, 'name' => $d->partner->name, 'nif' => $d->partner->nif, 'kind' => $d->partner->kind->value] : null,
            'purchase_order_id' => $d->purchase_order_id,
            'purchase_order' => $order ? ['id' => $order->id, 'number' => $order->number, 'status' => $order->status->value, 'status_label' => $order->status->label(), 'total_cents' => $order->totalCents(), 'project_id' => $order->project_id] : null,
            'cost_entry_id' => $d->cost_entry_id,
            'cost_center_id' => $d->cost_center_id,
            'cost_center' => $d->costCenter ? "{$d->costCenter->code} · {$d->costCenter->name}" : null,
            'posted_at' => $d->posted_at?->toIso8601String(),
            'posted_by' => $d->postedBy?->name,
            'created_by' => $d->createdBy?->name,
            'warnings' => $d->status === SupplierDocumentStatus::Posted ? [] : SupplierDocuments::warnings($d, $tenant),
            'suggestions' => [
                'purchase_orders' => SupplierDocuments::purchaseCandidates($d)->map(fn (array $c) => [
                    'id' => $c['order']->id,
                    'number' => $c['order']->number,
                    'project' => ['id' => $c['order']->project->id, 'code' => $c['order']->project->code, 'name' => $c['order']->project->name],
                    'status_label' => $c['order']->status->label(),
                    'total_cents' => $c['order']->totalCents(),
                    'reason' => $c['reason'],
                ])->values(),
            ],
            'options' => [
                'kinds' => SupplierDocumentKind::options(),
                'categories' => CostCategory::options(),
            ],
            'can' => [
                'edit' => $editable,
                'post' => $editable && $canPost && $d->kind !== SupplierDocumentKind::Other,
                'unpost' => $d->status === SupplierDocumentStatus::Posted && $canPost,
                'reject' => $editable,
                'reopen' => $d->status === SupplierDocumentStatus::Rejected && $open,
                'read' => $this->reader->enabled() && $open && $this->canRead($d),
                'delete' => in_array($d->status, [SupplierDocumentStatus::ToReview, SupplierDocumentStatus::Rejected], true) && $open,
                'create_supplier' => $editable && $d->partner_id === null && Gate::allows('purchases.manage'),
            ],
        ]);
    }
}
