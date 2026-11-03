<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/** Regras comuns às alterações de pastas e documentos de uma obra. */
trait GuardsDocumentWrites
{
    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para alterar os documentos.', 'PROJECT_ARCHIVED');
        }
    }

    /** documents.manage, ou documents.upload para quem enviou o documento. */
    private function authorizeDocumentEdit(Project $project, Document $document): void
    {
        if (Gate::allows('documents.manage', $project)) {
            return;
        }

        $this->authorize('documents.upload', $project);

        if ((int) $document->created_by_user_id !== (int) auth()->id()) {
            throw ApiException::forbidden('Só quem enviou o documento, ou quem gere os documentos da obra, o pode alterar.', 'DOCUMENT_NOT_OWNER');
        }
    }
}
