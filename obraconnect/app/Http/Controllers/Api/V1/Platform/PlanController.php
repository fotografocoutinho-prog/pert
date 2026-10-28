<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Planos, preços e limites configuráveis sem alterar código.
 * Planos não se apagam (há subscrições históricas): desativam-se.
 */
class PlanController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->withCount('subscriptions')->orderBy('sort_order')->get());
    }

    public function store(Request $request): PlanResource
    {
        $data = $this->validatePlan($request);
        $data['code'] = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('plans', 'code')],
        ])['code'];

        $plan = Plan::query()->create($data);
        $this->auditor->record('platform.plan_created', $plan, $data, tenantId: false);

        return new PlanResource($plan);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $plan->fill($this->validatePlan($request))->save();
        $this->auditor->record('platform.plan_updated', $plan, $this->auditor->changes($plan), tenantId: false);

        return new PlanResource($plan->loadCount('subscriptions'));
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly_cents' => ['nullable', 'integer', 'min:0'],
            'price_yearly_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'limits' => ['present', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['present', 'array'],
            'features.*' => ['string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
