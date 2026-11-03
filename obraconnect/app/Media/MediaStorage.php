<?php

namespace App\Media;

use App\Billing\PlanLimits;
use App\Jobs\GeneratePhotoPreviews;
use App\Models\DiaryEntry;
use App\Models\Media;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda e apaga fotografias, mantendo tenants.storage_used_bytes certo
 * (original + versões reduzidas). Atualizações atómicas: vários envios em
 * simultâneo não perdem contagens.
 */
class MediaStorage
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlanLimits $limits,
    ) {}

    /** $mime vem do conteúdo (getimagesize), não do que o cliente declara. */
    public function storeDiaryPhoto(Project $project, DiaryEntry $entry, UploadedFile $file, string $mime, ?string $caption, User $by): Media
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

        $media = new Media(['caption' => $caption, 'sort_order' => (int) $entry->photos()->max('sort_order') + 1]);
        $media->forceFill([
            'project_id' => $project->id,
            'diary_entry_id' => $entry->id,
            'disk' => $disk,
            'original_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by_user_id' => $by->id,
        ])->save();

        $this->addUsage($tenant, $media->size_bytes);

        GeneratePhotoPreviews::dispatch($media->id);

        return $media;
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->deleteDirectory($media->directory());
        $media->delete();

        $this->addUsage($this->tenants->tenantOrFail(), -($media->size_bytes + $media->derived_bytes));
    }

    /** Positivo soma, negativo subtrai (nunca abaixo de zero). */
    public function addUsage(Tenant $tenant, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        $query = DB::table('tenants')->where('id', $tenant->id);

        $bytes > 0
            ? $query->increment('storage_used_bytes', $bytes)
            : $query->update(['storage_used_bytes' => DB::raw(sprintf(
                'case when storage_used_bytes > %1$d then storage_used_bytes - %1$d else 0 end',
                -$bytes,
            ))]);

        $tenant->refresh();
    }
}
