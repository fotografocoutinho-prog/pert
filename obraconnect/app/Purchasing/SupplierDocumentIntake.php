<?php

namespace App\Purchasing;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Billing\StorageUsage;
use App\DocIntel\DocIntelClient;
use App\Enums\CostCategory;
use App\Enums\SupplierDocumentStatus;
use App\Exceptions\ApiException;
use App\Jobs\ReadSupplierDocument;
use App\Models\Project;
use App\Models\SupplierDocument;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Entrada de uma fatura ou guia de fornecedor (pela aplicação ou pela API pública): confirma o tipo
 * de ficheiro, recusa repetidos, conta o espaço, guarda o original e manda ler pelo DocIntel.
 */
final class SupplierDocumentIntake
{
    public function __construct(
        private readonly DocIntelClient $reader,
        private readonly PlanLimits $limits,
        private readonly StorageUsage $usage,
        private readonly Auditor $auditor,
    ) {}

    public function receive(Tenant $tenant, UploadedFile $file, ?Project $project, ?int $userId, string $via = 'app'): SupplierDocument
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, SupplierDocument::MIME_TYPES, true)) {
            throw ApiException::unprocessable('O conteúdo do ficheiro não é PDF nem fotografia.', 'SUPPLIER_DOC_FILE');
        }
        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = SupplierDocument::query()->where('sha256', $sha)->first()) {
            throw new ApiException("Este ficheiro já foi carregado (documento n.º {$existing->id}).", 'SUPPLIER_DOC_DUPLICATE_FILE', 409, ['id' => $existing->id]);
        }
        $this->limits->assertCanAdd($tenant, 'storage_mb', (int) ceil($file->getSize() / 1048576));

        $reading = $this->reader->enabled();
        $doc = DB::transaction(function () use ($file, $mime, $sha, $project, $reading, $userId): SupplierDocument {
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
                'created_by_user_id' => $userId,
            ])->save();
            $extension = strtolower($file->getClientOriginalExtension()) ?: ($mime === 'application/pdf' ? 'pdf' : 'jpg');
            $doc->forceFill(['path' => Storage::disk($doc->disk)->putFileAs($doc->directory(), $file, 'original-'.Str::lower(Str::random(8)).".{$extension}")])->save();

            return $doc;
        });
        $this->usage->add($tenant, $doc->size_bytes);
        $this->auditor->record('supplier_document.uploaded', $doc, ['file' => $doc->original_name, 'project' => $project?->code, 'via' => $via]);

        if ($reading) {
            ReadSupplierDocument::dispatch($doc->id);
        }

        return $doc;
    }
}
