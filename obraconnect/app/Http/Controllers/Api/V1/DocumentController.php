<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Documents\DocumentStorage;
use App\Http\Controllers\Api\V1\Concerns\GuardsDocumentWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Specialty;
use App\Structure\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de uma obra, com versões imutáveis.
 * documents.view consulta e descarrega; documents.upload envia (e junta versões aos seus);
 * documents.manage gere pastas, move, apaga e junta versões a qualquer documento.
 */
class DocumentController extends Controller
{
    use GuardsDocumentWrites;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly DocumentStorage $storage,
    ) {}

    /** Conteúdo de uma pasta (ou da raiz), ou resultados de pesquisa em toda a obra. */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('documents.view', $project);

        $search = trim((string) $request->query('search'));
        $specialty = $request->integer('specialty_id') ?: null;
        // Pesquisa ou filtro por especialidade: em todas as pastas.
        $everywhere = $search !== '' || $specialty !== null;
        $folder = ! $everywhere && $request->filled('folder_id')
            ? $project->folders()->findOrFail((int) $request->query('folder_id'))
            : null;

        $documents = $project->documents()
            ->with(['currentVersion.uploadedBy:id,name', 'createdBy:id,name'])
            ->withCount('versions')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhereHas('versions', fn ($v) => $v->where('original_name', 'like', $like)));
            })
            ->when($specialty !== null, fn ($q) => $q->where('specialty_id', $specialty))
            ->when(! $everywhere, fn ($q) => $q->where('folder_id', $folder?->id))
            ->orderBy('title')
            ->limit(500)
            ->get();

        $folders = $everywhere ? collect() : $project->folders()
            ->where('parent_id', $folder?->id)
            ->withCount(['children', 'documents'])
            ->orderBy('name')
            ->get();

        $data = [
            'folder' => $folder ? ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id] : null,
            'path' => $folder ? collect($folder->ancestry())->map(fn (DocumentFolder $f) => ['id' => $f->id, 'name' => $f->name]) : [],
            'folders' => $folders->map(fn (DocumentFolder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'items_count' => $f->children_count + $f->documents_count,
            ]),
            'documents' => DocumentResource::collection($documents),
        ];

        // Todas as pastas da obra (para "Mover para…"), só quando pedido.
        if ($request->boolean('tree')) {
            $data['tree'] = $project->folders()->orderBy('name')->get(['id', 'name', 'parent_id']);
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, Project $project): DocumentResource
    {
        $this->authorize('documents.upload', $project);
        $this->assertProjectOpen($project);

        $data = $request->validate($this->fileRules() + [
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'folder_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')->where('project_id', $project->id)],
            'specialty_id' => Specialty::rules(),
        ] + Place::rules($project), $this->fileMessages(), ['title' => 'título', 'folder_id' => 'pasta', 'specialty_id' => 'especialidade']);

        $file = $request->file('file');
        $this->storage->assertQuota($file);

        $document = DB::transaction(function () use ($project, $data, $file, $request): Document {
            $document = $project->documents()->make([
                'title' => filled($data['title'] ?? null) ? trim($data['title']) : (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Documento'),
                'description' => $data['description'] ?? null,
                'folder_id' => $data['folder_id'] ?? null,
                'specialty_id' => $data['specialty_id'] ?? null,
            ] + Place::normalize($project, array_intersect_key($data, array_flip(['location_id', 'element_id'])) + ['location_id' => null, 'element_id' => null]));
            $document->created_by_user_id = $request->user()->id;
            $document->save();

            $this->storage->storeVersion($document, $file, $data['note'] ?? null, $request->user());

            return $document;
        });

        $this->auditor->record('document.created', $document, [
            'project' => $project->code,
            'title' => $document->title,
            'file' => $file->getClientOriginalName(),
        ]);

        return new DocumentResource($this->loadDetail($document));
    }

    public function show(Project $project, Document $document): DocumentResource
    {
        $this->authorize('documents.view', $project);

        return new DocumentResource($this->loadDetail($document));
    }

    public function update(Request $request, Project $project, Document $document): DocumentResource
    {
        $this->authorizeDocumentEdit($project, $document);
        $this->assertProjectOpen($project);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'folder_id' => ['sometimes', 'nullable', 'integer', Rule::exists('document_folders', 'id')->where('project_id', $project->id)],
            'specialty_id' => Specialty::rules($document->specialty_id),
        ] + Place::rules($project), [], ['title' => 'título', 'folder_id' => 'pasta', 'specialty_id' => 'especialidade']);
        if (array_key_exists('element_id', $data) || array_key_exists('location_id', $data)) {
            $data = Place::normalize($project, $data);
        }
        $document->fill($data)->save();

        $changes = $this->auditor->changes($document);
        if ($changes['new'] !== []) {
            $this->auditor->record('document.updated', $document, $changes);
        }

        return new DocumentResource($this->loadDetail($document));
    }

    public function destroy(Project $project, Document $document): Response
    {
        $this->authorize('documents.manage', $project);
        $this->assertProjectOpen($project);

        $versions = $document->versions()->count();
        $this->storage->deleteDocument($document);

        $this->auditor->record('document.deleted', $project, ['title' => $document->title, 'versions' => $versions]);

        return response()->noContent();
    }

    public function storeVersion(Request $request, Project $project, Document $document): DocumentResource
    {
        $this->authorizeDocumentEdit($project, $document);
        $this->assertProjectOpen($project);

        $data = $request->validate($this->fileRules(), $this->fileMessages());
        $version = $this->storage->storeVersion($document, $request->file('file'), $data['note'] ?? null, $request->user());

        $this->auditor->record('document.version_added', $document, [
            'title' => $document->title,
            'number' => $version->number,
            'sha256' => $version->sha256,
        ]);

        return new DocumentResource($this->loadDetail($document));
    }

    /** Abre no browser (PDF e imagens) ou descarrega; cada pedido fica registado. */
    public function download(Request $request, Project $project, Document $document, DocumentVersion $version): StreamedResponse
    {
        $this->authorize('documents.view', $project);

        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404);

        $version->downloads()->create(['user_id' => $request->user()->id, 'ip_address' => $request->ip()]);

        $inline = ! $request->boolean('download') && in_array($version->mime, DocumentVersionResource::INLINE_TYPES, true);

        return $disk->response($version->path, $version->original_name, [
            // Tipos que o browser podia interpretar (HTML, SVG...) vão sempre como binário.
            'Content-Type' => $inline ? $version->mime : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }

    private function loadDetail(Document $document): Document
    {
        return $document->load([
            'folder:id,name',
            'createdBy:id,name',
            'currentVersion.uploadedBy:id,name',
            'versions' => fn ($q) => $q->with('uploadedBy:id,name')->withCount('downloads'),
        ])->loadCount('versions');
    }

    /** @return array<string, list<string>> */
    private function fileRules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:'.implode(',', config('obraconnect.files.document_extensions')), 'max:'.(config('obraconnect.files.document_max_mb') * 1024)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private function fileMessages(): array
    {
        return [
            'file.extensions' => 'Tipo de ficheiro não aceite. Aceites: '.implode(', ', config('obraconnect.files.document_extensions')).'.',
            'file.max' => 'O ficheiro não pode ter mais de '.config('obraconnect.files.document_max_mb').' MB.',
        ];
    }
}
