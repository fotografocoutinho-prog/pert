<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Http\Controllers\Api\V1\Concerns\GuardsInspectionWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Inspections\ChecklistItems;
use App\Inspections\EvidenceIndex;
use App\Media\MediaStorage;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Media;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Verificação dos pontos de uma inspeção em curso: resultado, medição, observações e fotografias
 * de evidência (inspections.perform). Cada resposta traz o índice de evidências atualizado.
 */
class InspectionItemController extends Controller
{
    use GuardsInspectionWrites;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const PHOTOS_PER_ITEM = 20;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly MediaStorage $media,
    ) {}

    public function update(Request $request, Project $project, Inspection $inspection, InspectionItem $item): JsonResponse
    {
        $this->authorizeFill($project, $inspection);

        $data = $request->validate([
            'result' => ['sometimes', 'nullable', Rule::enum(InspectionResult::class)],
            'measure_value' => ['sometimes', 'nullable', 'numeric', 'between:-9999999999,9999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [], ['result' => 'resultado', 'measure_value' => 'valor medido', 'notes' => 'observações']);

        $item->fill($data);
        if ($item->isDirty('result')) {
            $item->answered_by_user_id = $item->result === null ? null : $request->user()->id;
            $item->answered_at = $item->result === null ? null : now();
        }
        $item->save();

        return $this->respond($inspection, ['item' => $this->presentItem($item)]);
    }

    public function storePhoto(Request $request, Project $project, Inspection $inspection, InspectionItem $item): JsonResponse
    {
        $this->authorizeFill($project, $inspection);

        $maxMb = (int) config('obraconnect.files.photo_max_mb');
        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.($maxMb * 1024)],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photo.mimetypes' => 'Só são aceites fotografias JPEG, PNG ou WebP.',
            'photo.max' => "A fotografia não pode ter mais de {$maxMb} MB.",
        ], ['photo' => 'fotografia', 'caption' => 'legenda']);

        $info = @getimagesize($request->file('photo')->getRealPath());
        if (! $info || ! in_array($info['mime'], self::TYPES, true)) {
            throw ValidationException::withMessages(['photo' => 'Só são aceites fotografias JPEG, PNG ou WebP.']);
        }
        if ($item->photos()->count() >= self::PHOTOS_PER_ITEM) {
            throw ValidationException::withMessages(['photo' => 'Cada ponto pode ter no máximo '.self::PHOTOS_PER_ITEM.' fotografias.']);
        }

        $photo = $this->media->storeInspectionPhoto($project, $item, $request->file('photo'), $info['mime'], $request->input('caption'), $request->user());

        $this->auditor->record('inspection.photo_added', $inspection, [
            'project' => $project->code,
            'number' => $inspection->number,
            'file' => $photo->original_name,
            'sha256' => $photo->sha256,
        ]);

        return $this->respond($inspection, ['photo' => (new MediaResource($photo->refresh()->load('uploadedBy:id,name')))->resolve()], 201);
    }

    public function destroyPhoto(Project $project, Inspection $inspection, InspectionItem $item, Media $photo): JsonResponse
    {
        $this->authorizeFill($project, $inspection);

        $this->media->delete($photo);
        $this->auditor->record('inspection.photo_removed', $inspection, [
            'project' => $project->code,
            'number' => $inspection->number,
            'file' => $photo->original_name,
        ]);

        return $this->respond($inspection, []);
    }

    private function authorizeFill(Project $project, Inspection $inspection): void
    {
        $this->authorize('inspections.perform', $project);
        $this->assertProjectOpen($project);
        $this->assertStatus($inspection, [InspectionStatus::InProgress], 'alterar os pontos (só enquanto está em curso)');
    }

    /** @param  array<string, mixed>  $data */
    private function respond(Inspection $inspection, array $data, int $status = 200): JsonResponse
    {
        $inspection->load('items.photos');

        return response()->json(['data' => $data + ['evidence' => EvidenceIndex::evaluate($inspection)]], $status);
    }

    /** @return array<string, mixed> */
    private function presentItem(InspectionItem $item): array
    {
        return ChecklistItems::present($item) + [
            'result' => $item->result?->value,
            'result_label' => $item->result?->label(),
            'measure_value' => $item->measure_value === null ? null : (float) $item->measure_value,
            'out_of_tolerance' => $item->isOutOfTolerance(),
            'notes' => $item->notes,
            'answered_by' => $item->answeredBy?->name,
            'answered_at' => $item->answered_at?->toIso8601String(),
        ];
    }
}
