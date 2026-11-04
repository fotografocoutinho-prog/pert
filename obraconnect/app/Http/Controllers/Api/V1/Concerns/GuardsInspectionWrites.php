<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\InspectionStatus;
use App\Exceptions\ApiException;
use App\Inspections\InspectionWorkflow;
use App\Models\Inspection;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/** Regras comuns a tudo o que mexe em inspeções. */
trait GuardsInspectionWrites
{
    private function authorizeView(Project $project): void
    {
        abort_unless(Gate::any(InspectionWorkflow::VIEW_PERMISSIONS, $project), 403);
    }

    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ApiException::conflict('A obra está arquivada. Reabra-a para mexer nas inspeções.', 'PROJECT_ARCHIVED');
        }
    }

    /** @param  list<InspectionStatus>  $allowed */
    private function assertStatus(Inspection $inspection, array $allowed, string $doing): void
    {
        if (! in_array($inspection->status, $allowed, true)) {
            throw ApiException::conflict(
                "A inspeção n.º {$inspection->number} está «{$inspection->status->label()}»: não é possível {$doing}.",
                'INSPECTION_STATUS',
            );
        }
    }
}
