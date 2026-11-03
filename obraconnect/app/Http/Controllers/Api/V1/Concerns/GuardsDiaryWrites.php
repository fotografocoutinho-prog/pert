<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\ApiException;
use App\Models\DiaryEntry;
use App\Models\Project;

/** Regras comuns a tudo o que altera o diário (registos e fotografias). */
trait GuardsDiaryWrites
{
    private function assertDraft(DiaryEntry $entry): void
    {
        if (! $entry->isDraft()) {
            throw ApiException::conflict('Este registo já foi submetido e não pode ser alterado.', 'DIARY_SUBMITTED');
        }
    }

    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para escrever no diário.', 'PROJECT_ARCHIVED');
        }
    }
}
