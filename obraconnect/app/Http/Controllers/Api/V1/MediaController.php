<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Inspections\InspectionWorkflow;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve os ficheiros das fotografias. Não há endereços públicos: cada pedido passa
 * pela sessão, pelo contexto da empresa e pela permissão de quem é dono da foto.
 */
class MediaController extends Controller
{
    public function show(Project $project, Media $media, string $variant): StreamedResponse
    {
        // Cada dono tem a sua regra: evidências de inspeção para quem vê inspeções, o resto pelo diário.
        if ($media->inspection_item_id !== null) {
            abort_unless(Gate::any(InspectionWorkflow::VIEW_PERMISSIONS, $project), 403);
        } else {
            $this->authorize('diary.view', $project);
        }

        $path = match ($variant) {
            'thumb' => $media->thumb_path,
            'preview' => $media->preview_path,
            default => $media->original_path,
        };
        $disk = Storage::disk($media->disk);
        abort_if($path === null || ! $disk->exists($path), 404);

        $original = $variant === 'original';

        return $disk->response($path, $original ? $media->original_name : null, [
            'Content-Type' => $original ? $media->mime : 'image/jpeg',
            // O conteúdo de cada endereço nunca muda; privado para não ficar em caches partilhadas.
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], $original ? 'attachment' : 'inline');
    }
}
