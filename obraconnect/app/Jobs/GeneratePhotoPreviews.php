<?php

namespace App\Jobs;

use App\Billing\StorageUsage;
use App\Enums\MediaStatus;
use App\Models\Media;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Gera a pré-visualização e a miniatura (JPEG) de uma fotografia, com a orientação
 * EXIF aplicada, e lê a data em que foi tirada. O original não é tocado.
 *
 * As versões geradas não levam metadados (nem GPS): o GD não os copia.
 * A empresa vem do Context do Laravel, como em ExportTenantData.
 */
class GeneratePhotoPreviews implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId) {}

    public function handle(TenantContext $tenants, StorageUsage $usage): void
    {
        if (! $tenants->hasTenant()) {
            throw new MissingTenantContextException('GeneratePhotoPreviews sem empresa no contexto.');
        }

        $media = Media::query()->find($this->mediaId);
        if ($media === null || $media->status !== MediaStatus::Processing) {
            return;
        }

        $disk = Storage::disk($media->disk);

        try {
            $source = $disk->path($media->original_path);
            [$image, $exif] = $this->load($source, $media->mime);

            $width = imagesx($image);
            $height = imagesy($image);
            $derived = 0;
            $paths = [];

            foreach (['preview' => config('obraconnect.files.photo_preview_px'), 'thumb' => config('obraconnect.files.photo_thumb_px')] as $variant => $max) {
                $path = $media->directory()."/{$variant}.jpg";
                $bytes = $this->jpeg($image, (int) $max, $variant === 'thumb' ? 75 : 82);
                $disk->put($path, $bytes);
                $paths["{$variant}_path"] = $path;
                $derived += strlen($bytes);
            }
            imagedestroy($image);

            $media->forceFill($paths + [
                'status' => MediaStatus::Ready,
                'width' => $width,
                'height' => $height,
                'taken_at' => $this->takenAt($exif),
                'derived_bytes' => $derived,
            ])->save();

            $usage->add($tenants->tenantOrFail(), $derived);
        } catch (Throwable $e) {
            // O original continua disponível; só não há versões reduzidas.
            $media->forceFill(['status' => MediaStatus::Failed])->save();
            Log::warning('Fotografia sem pré-visualização', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: GdImage, 1: array<string, mixed>} */
    private function load(string $path, string $mime): array
    {
        $info = @getimagesize($path) ?: throw new RuntimeException('Não é uma imagem válida.');
        $megapixels = $info[0] * $info[1] / 1_000_000;
        if ($megapixels > config('obraconnect.files.photo_max_megapixels')) {
            throw new RuntimeException(sprintf('Imagem demasiado grande para gerar pré-visualização (%.0f MP).', $megapixels));
        }

        $image = match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => @imagecreatefromjpeg($path),
        };
        if (! $image instanceof GdImage) {
            throw new RuntimeException('O GD não conseguiu ler a imagem.');
        }

        $exif = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (@exif_read_data($path) ?: []) : [];

        // Telemóveis gravam a foto "deitada" e indicam a rotação no EXIF.
        $image = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return [$image, $exif];
    }

    private function jpeg(GdImage $image, int $max, int $quality): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));

        $resized = $scale < 1
            ? imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC)
            : $image;

        // Fundo branco para PNG/WebP com transparência (o JPEG não a tem).
        $canvas = imagecreatetruecolor(imagesx($resized), imagesy($resized));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $resized, 0, 0, 0, 0, imagesx($resized), imagesy($resized));

        ob_start();
        imagejpeg($canvas, null, $quality);
        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);
        if ($resized !== $image) {
            imagedestroy($resized);
        }

        return $bytes;
    }

    /** O EXIF não tem fuso: assume-se a hora local da obra. */
    private function takenAt(array $exif): ?Carbon
    {
        $value = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y:m:d H:i:s', trim($value), 'Europe/Lisbon')->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
