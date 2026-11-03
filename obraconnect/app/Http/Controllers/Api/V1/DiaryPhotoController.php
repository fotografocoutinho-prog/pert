<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Http\Controllers\Api\V1\Concerns\GuardsDiaryWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Media\MediaStorage;
use App\Models\DiaryEntry;
use App\Models\Media;
use App\Models\Project;
use App\Structure\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Fotografias de um registo do diário. Seguem as regras do registo: só se juntam,
 * legendam ou apagam enquanto ele está em rascunho (diary.write).
 */
class DiaryPhotoController extends Controller
{
    use GuardsDiaryWrites;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly MediaStorage $media,
    ) {}

    public function store(Request $request, Project $project, DiaryEntry $diaryEntry): MediaResource
    {
        $this->authorize('diary.write', $project);

        // Reenvio de uma fotografia tirada sem rede: devolve a que já existe (não duplica nem conta duas vezes).
        $uuid = $request->validate(['client_uuid' => ['nullable', 'uuid']])['client_uuid'] ?? null;
        if ($uuid !== null && ($existing = $diaryEntry->photos()->where('client_uuid', $uuid)->first())) {
            return new MediaResource($existing->load('uploadedBy:id,name'));
        }

        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $maxMb = (int) config('obraconnect.files.photo_max_mb');
        $request->validate([
            // Pelo conteúdo, não pela extensão. HEIC fica de fora: o iPhone converte para JPEG ao enviar.
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.($maxMb * 1024)],
            'caption' => ['nullable', 'string', 'max:255'],
        ] + Place::rules($project), [
            'photo.mimetypes' => 'Só são aceites fotografias JPEG, PNG ou WebP.',
            'photo.max' => "A fotografia não pode ter mais de {$maxMb} MB.",
        ], ['photo' => 'fotografia', 'caption' => 'legenda']);

        // Segunda verificação, pelo próprio conteúdo: tem de ser uma imagem que o PHP consegue ler.
        $info = @getimagesize($request->file('photo')->getRealPath());
        if (! $info || ! in_array($info['mime'], self::TYPES, true)) {
            throw ValidationException::withMessages(['photo' => 'Só são aceites fotografias JPEG, PNG ou WebP.']);
        }

        $limit = (int) config('obraconnect.files.photos_per_entry');
        if ($diaryEntry->photos()->count() >= $limit) {
            throw ValidationException::withMessages(['photo' => "Cada registo pode ter no máximo {$limit} fotografias."]);
        }

        $photo = $this->media->storeDiaryPhoto($project, $diaryEntry, $request->file('photo'), $info['mime'], $request->input('caption'), $request->user(), $uuid);
        $photo->update(Place::normalize($project, $request->only(['location_id', 'element_id'])));

        $this->auditor->record('diary.photo_added', $diaryEntry, [
            'project' => $project->code,
            'date' => $diaryEntry->entry_date->toDateString(),
            'file' => $photo->original_name,
            'sha256' => $photo->sha256,
        ]);

        return new MediaResource($photo->refresh()->load('uploadedBy:id,name'));
    }

    public function update(Request $request, Project $project, DiaryEntry $diaryEntry, Media $photo): MediaResource
    {
        $this->authorize('diary.write', $project);
        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $data = $request->validate(['caption' => ['sometimes', 'nullable', 'string', 'max:255']] + Place::rules($project), [], ['caption' => 'legenda']);
        $photo->update(array_key_exists('element_id', $data) || array_key_exists('location_id', $data) ? Place::normalize($project, $data) : $data);

        return new MediaResource($photo->load('uploadedBy:id,name'));
    }

    public function destroy(Project $project, DiaryEntry $diaryEntry, Media $photo): Response
    {
        $this->authorize('diary.write', $project);
        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $this->media->delete($photo);

        $this->auditor->record('diary.photo_removed', $diaryEntry, [
            'project' => $project->code,
            'date' => $diaryEntry->entry_date->toDateString(),
            'file' => $photo->original_name,
        ]);

        return response()->noContent();
    }
}
