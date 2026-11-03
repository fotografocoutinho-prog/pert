<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsDocumentWrites;
use App\Http\Controllers\Controller;
use App\Models\DocumentFolder;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pastas de documentos de uma obra (documents.manage). Só se apagam vazias. */
class DocumentFolderController extends Controller
{
    use GuardsDocumentWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('documents.manage', $project);
        $this->assertProjectOpen($project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')->where('project_id', $project->id)],
        ], [], ['name' => 'nome da pasta', 'parent_id' => 'pasta']);

        $this->assertUniqueName($project, $data['parent_id'] ?? null, $data['name']);

        $folder = $project->folders()->make(['name' => trim($data['name']), 'parent_id' => $data['parent_id'] ?? null]);
        $folder->created_by_user_id = $request->user()->id;
        $folder->save();

        $this->auditor->record('document.folder_created', $folder, ['project' => $project->code, 'name' => $folder->name]);

        return response()->json(['data' => $this->present($folder)], 201);
    }

    public function update(Request $request, Project $project, DocumentFolder $folder): JsonResponse
    {
        $this->authorize('documents.manage', $project);
        $this->assertProjectOpen($project);

        $data = $request->validate(['name' => ['required', 'string', 'max:150']], [], ['name' => 'nome da pasta']);
        $this->assertUniqueName($project, $folder->parent_id, $data['name'], $folder->id);

        $old = $folder->name;
        $folder->update(['name' => trim($data['name'])]);

        $this->auditor->record('document.folder_renamed', $folder, ['old' => ['name' => $old], 'new' => ['name' => $folder->name]]);

        return response()->json(['data' => $this->present($folder)]);
    }

    public function destroy(Project $project, DocumentFolder $folder): Response
    {
        $this->authorize('documents.manage', $project);
        $this->assertProjectOpen($project);

        if ($folder->children()->exists() || $folder->documents()->exists()) {
            throw ApiException::conflict('A pasta não está vazia. Mova ou apague primeiro o que tem dentro.', 'FOLDER_NOT_EMPTY');
        }

        $folder->delete();
        $this->auditor->record('document.folder_deleted', $project, ['name' => $folder->name]);

        return response()->noContent();
    }

    /** Sem distinguir maiúsculas: "Projeto" e "projeto" na mesma pasta confundem. */
    private function assertUniqueName(Project $project, ?int $parentId, string $name, ?int $ignore = null): void
    {
        $exists = $project->folders()
            ->where('parent_id', $parentId)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->get(['id', 'name'])
            ->contains(fn (DocumentFolder $f) => mb_strtolower($f->name) === mb_strtolower(trim($name)));

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Já existe uma pasta com este nome aqui.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(DocumentFolder $folder): array
    {
        return ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id];
    }
}
