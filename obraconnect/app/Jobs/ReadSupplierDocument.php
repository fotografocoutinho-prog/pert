<?php

namespace App\Jobs;

use App\DocIntel\DocIntelClient;
use App\Enums\SupplierDocumentStatus;
use App\Integrations\Payloads;
use App\Integrations\Webhooks;
use App\Models\SupplierDocument;
use App\Purchasing\SupplierDocuments;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manda o ficheiro ao DocIntel e preenche o documento com o que foi lido. Corra bem ou mal, o
 * documento fica "por rever": se a leitura falhar, preenche-se à mão (o erro fica à vista).
 * A empresa vem do Context do Laravel, como nos outros trabalhos em fila.
 */
class ReadSupplierDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $documentId) {}

    public function handle(TenantContext $tenants, DocIntelClient $client): void
    {
        if (! $tenants->hasTenant()) {
            throw new MissingTenantContextException('ReadSupplierDocument sem empresa no contexto.');
        }

        $doc = SupplierDocument::query()->find($this->documentId);
        if ($doc === null || $doc->status !== SupplierDocumentStatus::Reading) {
            return;
        }

        try {
            $result = $client->analyze(
                Storage::disk($doc->disk)->path($doc->path),
                $doc->original_name,
                "obraconnect:{$doc->tenant_id}:{$doc->id}",
            );
            SupplierDocuments::applyReading($doc, $result);
            $doc->read_error = null;
        } catch (Throwable $e) {
            $doc->read_error = Str::limit($e->getMessage(), 900);
            Log::warning('Documento de fornecedor não lido', ['supplier_document_id' => $doc->id, 'error' => $e->getMessage()]);
        }

        $doc->forceFill(['status' => SupplierDocumentStatus::ToReview, 'read_at' => now()])->save();
        Webhooks::dispatch('supplier_document.read', fn () => Payloads::supplierDocument($doc));
    }

    /** Tempo esgotado ou erro fora do try: não deixar o documento preso em "a ler". */
    public function failed(?Throwable $e): void
    {
        SupplierDocument::withoutGlobalScopes()->whereKey($this->documentId)
            ->where('status', SupplierDocumentStatus::Reading->value)
            ->update([
                'status' => SupplierDocumentStatus::ToReview->value,
                'read_error' => Str::limit('A leitura não terminou: '.($e?->getMessage() ?? 'erro desconhecido'), 900),
                'read_at' => now(),
            ]);
    }
}
