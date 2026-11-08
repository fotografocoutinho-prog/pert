<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\ApiException;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/** Regras comuns aos módulos económicos da obra. */
trait GuardsProjectWrites
{
    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para fazer alterações.', 'PROJECT_ARCHIVED');
        }
    }

    /** @param  list<string>  $permissions */
    private function authorizeAny(array $permissions, ?Project $project = null): void
    {
        abort_unless($project ? Gate::any($permissions, $project) : Gate::any($permissions), 403);
    }

    /** "12,5" ou "12.5" → 12.5 (os campos numéricos aceitam vírgula). */
    private static function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }
}
