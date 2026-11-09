<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Audit\Auditor;
use App\Economics\CertificateBuilder;
use App\Enums\CertificateStatus;
use App\Enums\ContractStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateLine;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Models\PartnerPortalUser;
use App\Notifications\Notifier;
use App\Portal\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal de subempreiteiros. Tudo é filtrado pelo subempreiteiro do acesso (middleware "portal"):
 * só os seus contratos ativos ou fechados, os autos desses contratos e os seus documentos.
 */
class PortalController extends Controller
{
    public const DOCUMENT_MAX_KB = 20 * 1024;

    public function __construct(private readonly Auditor $auditor) {}

    /** Os acessos do utilizador (pode trabalhar para mais do que uma empresa). */
    public function accesses(Request $request, PortalAccess $portal): JsonResponse
    {
        return response()->json(['data' => $portal->accessesFor($request->user())->map(fn (PartnerPortalUser $a) => [
            'id' => $a->id,
            'company' => $a->tenant->name,
            'partner' => $a->partner->name,
        ])->all()]);
    }

    public function contracts(Request $request): JsonResponse
    {
        $partner = $this->partner($request);
        $contracts = $this->visibleContracts($partner)->with(['project:id,code,name', 'items', 'certificates'])->orderByDesc('id')->get();

        return response()->json(['data' => [
            'company' => $this->tenantContext()->tenantOrFail()->name,
            'partner' => ['id' => $partner->id, 'name' => $partner->name, 'nif' => $partner->nif],
            'contracts' => $contracts->map(fn (Contract $c) => $this->summary($c))->all(),
        ]]);
    }

    public function contract(Request $request, string $access, string $contract): JsonResponse
    {
        $c = $this->findContract($request, $contract);

        return response()->json(['data' => $this->detail($c)]);
    }

    /**
     * Proposta de auto: as quantidades do período, por artigo. Entra como "submetido": a empresa aprova
     * ou devolve, como num auto feito por ela.
     */
    public function propose(Request $request, string $access, string $contract, Notifier $notifier): JsonResponse
    {
        $c = $this->findContract($request, $contract);
        if ($c->status !== ContractStatus::Active) {
            throw ApiException::conflict('Só se propõem autos de contratos em curso.', 'CONTRACT_STATUS');
        }
        if ($c->project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada.', 'PROJECT_ARCHIVED');
        }
        if ($c->certificates()->whereIn('status', [CertificateStatus::Draft->value, CertificateStatus::Submitted->value])->exists()) {
            throw ApiException::conflict('Já há um auto deste contrato por aprovar. Espere pela decisão da empresa.', 'CERTIFICATE_PENDING');
        }

        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.contract_item_id' => ['required', 'integer'],
            'lines.*.qty_period' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], [], ['period_start' => 'início do período', 'period_end' => 'fim do período', 'lines' => 'quantidades']);

        $quantities = collect($data['lines'])->mapWithKeys(fn ($l) => [(int) $l['contract_item_id'] => round((float) $l['qty_period'], 3)]);
        $unknown = $quantities->keys()->diff($c->items->pluck('id'));
        if ($unknown->isNotEmpty()) {
            throw ApiException::unprocessable('Há artigos que não são deste contrato.', 'CONTRACT_ITEM_INVALID');
        }
        if ($quantities->sum() <= 0) {
            throw ApiException::unprocessable('Indique a quantidade feita no período em pelo menos um artigo.', 'CERTIFICATE_EMPTY');
        }

        /** @var PartnerPortalUser $portalUser */
        $portalUser = $request->attributes->get('portal_access');
        $certificate = DB::transaction(function () use ($c, $data, $quantities, $portalUser): Certificate {
            $certificate = CertificateBuilder::create($c->project, $data, $c->load('items'), null, null);
            foreach ($certificate->lines as $line) {
                /** @var CertificateLine $line */
                $qty = (float) ($quantities[$line->contract_item_id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $left = (float) $line->qty_contract - (float) $line->qty_previous;
                if ($qty > $left + 0.0005) {
                    throw ApiException::unprocessable("«{$line->description}»: a quantidade ({$this->num($qty)} {$line->unit}) passa o que falta do contrato ({$this->num(max(0, $left))} {$line->unit}). Fale com a empresa para rever o contrato.", 'CERTIFICATE_OVER_CONTRACT');
                }
                $line->forceFill(['qty_period' => $qty, 'amount_cents' => (int) round($qty * $line->unit_price_cents)])->save();
            }
            CertificateBuilder::recompute($certificate);
            $certificate->forceFill(['status' => CertificateStatus::Submitted, 'submitted_at' => now(), 'proposed_by_portal_user_id' => $portalUser->id])->save();

            return $certificate;
        });

        $this->auditor->record('certificate.proposed_by_portal', $certificate, ['project' => $c->project->code, 'contract' => $c->code, 'number' => $certificate->number, 'by' => $portalUser->email]);
        $notifier->portalProposal($c->project, $c, $certificate, $portalUser);

        return response()->json(['data' => $this->detail($c->refresh())], 201);
    }

    public function documents(Request $request): JsonResponse
    {
        $partner = $this->partner($request);

        return response()->json(['data' => PartnerDocument::query()->where('partner_id', $partner->id)->orderBy('expires_on')->orderBy('id')->get()
            ->map(fn (PartnerDocument $d) => self::presentDocument($d))->values()->all()]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $partner = $this->partner($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png', 'max:'.self::DOCUMENT_MAX_KB],
        ], [], ['title' => 'documento', 'expires_on' => 'validade', 'file' => 'ficheiro']);

        /** @var PartnerPortalUser $portalUser */
        $portalUser = $request->attributes->get('portal_access');
        $document = self::storeFile($partner, $data, $request->file('file'), $portalUser->id);
        $this->auditor->record('partner.document_uploaded_by_portal', $document, ['partner' => $partner->name, 'title' => $document->title, 'by' => $portalUser->email]);

        return response()->json(['data' => self::presentDocument($document)], 201);
    }

    public function documentFile(Request $request, string $access, string $document): StreamedResponse
    {
        $partner = $this->partner($request);
        $doc = PartnerDocument::query()->where('partner_id', $partner->id)->whereKey((int) $document)->first();
        abort_if($doc === null || $doc->path === null, 404);

        return self::download($doc);
    }

    // --- partilhado com o lado da empresa ---

    /** @param  array{title: string, reference?: string|null, expires_on?: string|null}  $data */
    public static function storeFile(Partner $partner, array $data, UploadedFile $file, ?int $portalUserId): PartnerDocument
    {
        $disk = (string) config('obraconnect.files.disk');
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $path = $file->storeAs("tenants/{$partner->tenant_id}/partner-documents/{$partner->id}", $name, $disk);

        $document = $partner->documents()->create([
            'title' => $data['title'],
            'reference' => $data['reference'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
        ]);
        $document->forceFill([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'uploaded_by_portal_user_id' => $portalUserId,
        ])->save();

        return $document;
    }

    public static function download(PartnerDocument $doc): StreamedResponse
    {
        $disk = Storage::disk($doc->disk ?: config('obraconnect.files.disk'));
        abort_unless($disk->exists($doc->path), 404);

        return $disk->download($doc->path, $doc->original_name ?: basename($doc->path), [
            'Content-Type' => $doc->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function presentDocument(PartnerDocument $d): array
    {
        $state = $d->expires_on === null ? 'valid' : ($d->expires_on->isPast() ? 'expired' : ($d->expires_on->lt(now()->addDays(30)) ? 'expiring' : 'valid'));

        return [
            'id' => $d->id,
            'title' => $d->title,
            'reference' => $d->reference,
            'expires_on' => $d->expires_on?->toDateString(),
            'state' => $state,
            'has_file' => $d->path !== null,
            'original_name' => $d->original_name,
            'size_bytes' => $d->size_bytes,
            'from_portal' => $d->uploaded_by_portal_user_id !== null,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    // --- interno ---

    private function partner(Request $request): Partner
    {
        return $request->attributes->get('portal_partner');
    }

    private function visibleContracts(Partner $partner)
    {
        return Contract::query()->where('partner_id', $partner->id)->whereIn('status', [ContractStatus::Active->value, ContractStatus::Closed->value]);
    }

    private function findContract(Request $request, string $id): Contract
    {
        $c = ctype_digit($id) ? $this->visibleContracts($this->partner($request))->with(['project', 'items', 'certificates.lines'])->whereKey((int) $id)->first() : null;

        return $c ?? throw new ApiException('Contrato não encontrado.', 'NOT_FOUND', 404);
    }

    private function summary(Contract $c): array
    {
        $approved = $c->certificates->where('status', CertificateStatus::Approved);
        $value = $c->valueCents();
        $certified = (int) $approved->sum('amount_cents');
        $pending = $c->certificates->first(fn (Certificate $x) => $x->status !== CertificateStatus::Approved);

        return [
            'id' => $c->id,
            'code' => $c->code,
            'title' => $c->title,
            'project' => ['code' => $c->project->code, 'name' => $c->project->name],
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'retention_pct' => (float) $c->retention_pct,
            'value_cents' => $value,
            'certified_cents' => $certified,
            'retained_cents' => (int) $approved->sum('retention_cents'),
            'certified_pct' => $value > 0 ? round($certified * 100 / $value, 1) : null,
            // Um rascunho feito pela empresa é trabalho interno: diz-se só que está em preparação, sem valores.
            'pending_certificate' => $pending ? ['number' => $pending->number, 'status' => $pending->status->value,
                'status_label' => self::isInternalDraft($pending) ? 'Em preparação na empresa' : $pending->status->label()] : null,
        ];
    }

    private function detail(Contract $c): array
    {
        $c->loadMissing(['project', 'items', 'certificates.lines']);
        $approvedLines = $c->certificates->where('status', CertificateStatus::Approved)->flatMap->lines;

        return $this->summary($c) + [
            'items' => $c->items->map(function (ContractItem $i) use ($approvedLines) {
                $done = (float) $approvedLines->where('contract_item_id', $i->id)->sum(fn ($l) => (float) $l->qty_period);

                return [
                    'id' => $i->id,
                    'description' => $i->description,
                    'unit' => $i->unit,
                    'quantity' => (float) $i->quantity,
                    'unit_price_cents' => $i->unit_price_cents,
                    'value_cents' => (int) round((float) $i->quantity * $i->unit_price_cents),
                    'certified_qty' => round($done, 3),
                    'remaining_qty' => round(max(0, (float) $i->quantity - $done), 3),
                ];
            })->values()->all(),
            'certificates' => $c->certificates->reject(fn (Certificate $x) => self::isInternalDraft($x))->sortByDesc('number')->values()->map(fn (Certificate $x) => [
                'id' => $x->id,
                'number' => $x->number,
                'period_start' => $x->period_start->toDateString(),
                'period_end' => $x->period_end->toDateString(),
                'status' => $x->status->value,
                'status_label' => $x->status->label(),
                'amount_cents' => $x->amount_cents,
                'retention_cents' => $x->retention_cents,
                'net_cents' => $x->amount_cents - $x->retention_cents,
                'proposed_here' => $x->proposed_by_portal_user_id !== null,
                'submitted_at' => $x->submitted_at?->toIso8601String(),
                'approved_at' => $x->approved_at?->toIso8601String(),
                'lines' => $x->lines->where('qty_period', '!=', 0)->values()->map(fn (CertificateLine $l) => [
                    'description' => $l->description,
                    'unit' => $l->unit,
                    'qty_period' => (float) $l->qty_period,
                    'amount_cents' => $l->amount_cents,
                ])->all(),
            ])->all(),
            'can_propose' => $c->status === ContractStatus::Active && ! $c->project->isArchived()
                && ! $c->certificates->contains(fn (Certificate $x) => $x->status !== CertificateStatus::Approved),
        ];
    }

    private static function isInternalDraft(Certificate $x): bool
    {
        return $x->status === CertificateStatus::Draft && $x->proposed_by_portal_user_id === null;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
