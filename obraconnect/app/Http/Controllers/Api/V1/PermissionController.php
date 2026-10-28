<?php

namespace App\Http\Controllers\Api\V1;

use App\Access\PermissionRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('roles.view');

        $modules = collect(PermissionRegistry::modules())->map(fn (array $module, string $key) => [
            'module' => $key,
            'label' => $module['label'],
            'permissions' => collect($module['permissions'])
                ->map(fn (string $label, string $permission) => ['key' => $permission, 'label' => $label])
                ->values(),
        ])->values();

        return response()->json(['data' => $modules]);
    }
}
