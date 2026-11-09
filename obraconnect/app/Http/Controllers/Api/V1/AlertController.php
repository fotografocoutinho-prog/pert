<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\AccessResolver;
use App\Http\Controllers\Controller;
use App\Models\ProjectAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Alertas automáticos abertos. Cada um só aparece a quem vê a obra e essa parte dela (custos,
 * planeamento, medições, inspeções) ou, nos alertas da empresa, a quem tem a permissão respetiva.
 */
class AlertController extends Controller
{
    public function index(Request $request, AccessResolver $access): JsonResponse
    {
        $membership = $this->tenantContext()->membership();
        $visible = $membership ? $access->visibleProjectIds($membership) : [];
        $projectId = $request->integer('project_id') ?: null;

        $alerts = ProjectAlert::query()->open()->with('project:id,code,name')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get()
            ->filter(function (ProjectAlert $a) use ($visible): bool {
                if ($a->project_id === null) {
                    return Gate::any($a->type->permissions());
                }
                if ($visible !== null && ! in_array($a->project_id, $visible, true)) {
                    return false;
                }

                return $a->project !== null && Gate::any($a->type->permissions(), $a->project);
            })
            ->sort(fn (ProjectAlert $a, ProjectAlert $b) => [$a->level === 'danger' ? 0 : 1, -$a->opened_at->getTimestamp(), $a->id]
                <=> [$b->level === 'danger' ? 0 : 1, -$b->opened_at->getTimestamp(), $b->id])
            ->values();

        return response()->json(['data' => $alerts->map(fn (ProjectAlert $a) => [
            'id' => $a->id,
            'type' => $a->type->value,
            'type_label' => $a->type->label(),
            'level' => $a->level,
            'title' => $a->title,
            'detail' => $a->detail,
            'project' => $a->project ? ['id' => $a->project->id, 'code' => $a->project->code, 'name' => $a->project->name] : null,
            'path' => $a->path(),
            'opened_at' => $a->opened_at->toIso8601String(),
        ])->all()]);
    }
}
