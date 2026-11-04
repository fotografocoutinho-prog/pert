<?php

namespace App\Media;

use App\Billing\PlanLimits;
use App\Billing\StorageUsage;
use App\Jobs\GeneratePhotoPreviews;
use App\Models\DiaryEntry;
use App\Models\InspectionItem;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda e apaga fotografias, mantendo o espaço ocupado da empresa certo
 * (original + versões reduzidas).
 */
class MediaStorage
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlanLimits $limits,
        private readonly StorageUsage $usage,
    ) {}

    /** $mime vem do conteúdo (getimagesize), não do que o cliente declara. */
    public function storeDiaryPhoto(Project $project, DiaryEntry $entry, UploadedFile $file, string $mime, ?string $caption, User $by, ?string $clientUuid = null): Media
    {
        return $this->store($project, ['diary_entry_id' => $entry->id], (int) $entry->photos()->max('sort_order') + 1, $file, $mime, $caption, $by, $clientUuid);
    }

    /** Fotografia de evidência de um ponto de uma inspeção. */
    public function storeInspectionPhoto(Project $project, InspectionItem $item, UploadedFile $file, string $mime, ?string $caption, User $by): Media
    {
        return $this->store($project, ['inspection_item_id' => $item->id], (int) $item->photos()->max('sort_order') + 1, $file, $mime, $caption, $by);
    }

    /** @param  array<string, int>  $owner  coluna do dono => id */
    private function store(Project $project, array $owner, int $sortOrder, UploadedFile $file, string $mime, ?string $caption, User $by, ?string $clientUuid = null): Media
    {
        $tenant = $this->tenants->tenantOrFail();
        $this->limits->assertCanAdd($tenant, 'storage_mb', (int) ceil($file->getSize() / 1048576));

        $disk = config('obraconnect.files.disk');
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $directory = "tenants/{$tenant->id}/projects/{$project->id}/media/".Str::uuid();
        $path = Storage::disk($disk)->putFileAs($directory, $file, "original.{$extension}");

        $media = new Media(['caption' => $caption, 'sort_order' => $sortOrder]);
        $media->forceFill($owner + [
            'project_id' => $project->id,
            'disk' => $disk,
            'original_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by_user_id' => $by->id,
            'client_uuid' => $clientUuid,
        ])->save();

        $this->usage->add($tenant, $media->size_bytes);

        GeneratePhotoPreviews::dispatch($media->id);

        return $media;
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->deleteDirectory($media->directory());
        $media->delete();

        $this->usage->add($this->tenants->tenantOrFail(), -($media->size_bytes + $media->derived_bytes));
    }
}
