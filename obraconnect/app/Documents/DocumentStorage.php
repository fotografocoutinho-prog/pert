<?php

namespace App\Documents;

use App\Billing\PlanLimits;
use App\Billing\StorageUsage;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Grava versões de documentos (imutáveis, numeradas) e apaga documentos inteiros,
 * mantendo o espaço ocupado da empresa certo.
 */
class DocumentStorage
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlanLimits $limits,
        private readonly StorageUsage $usage,
    ) {}

    /** Chamar antes de criar o documento, para não ficar um documento sem versões. */
    public function assertQuota(UploadedFile $file): void
    {
        $this->limits->assertCanAdd($this->tenants->tenantOrFail(), 'storage_mb', (int) ceil($file->getSize() / 1048576));
    }

    public function storeVersion(Document $document, UploadedFile $file, ?string $note, User $by): DocumentVersion
    {
        $this->assertQuota($file);
        $disk = config('obraconnect.files.disk');

        $version = DB::transaction(function () use ($document, $file, $note, $by, $disk): DocumentVersion {
            // O bloqueio impede duas versões com o mesmo número em envios simultâneos.
            $number = (int) $document->versions()->reorder()->lockForUpdate()->max('number') + 1;
            $extension = strtolower($file->getClientOriginalExtension());
            $name = "v{$number}-".Str::lower(Str::random(8)).($extension !== '' ? ".{$extension}" : '');

            $version = new DocumentVersion;
            $version->forceFill([
                'document_id' => $document->id,
                'number' => $number,
                'disk' => $disk,
                'path' => Storage::disk($disk)->putFileAs($document->directory(), $file, $name),
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                // Pelo conteúdo (finfo), não pelo que o cliente declara.
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'note' => $note,
                'uploaded_by_user_id' => $by->id,
            ])->save();

            return $version;
        });

        $this->usage->add($this->tenants->tenantOrFail(), $version->size_bytes);
        $document->touch();

        return $version;
    }

    public function deleteDocument(Document $document): void
    {
        $bytes = (int) $document->versions()->reorder()->sum('size_bytes');

        Storage::disk(config('obraconnect.files.disk'))->deleteDirectory($document->directory());
        $document->delete();

        $this->usage->add($this->tenants->tenantOrFail(), -$bytes);
    }
}
