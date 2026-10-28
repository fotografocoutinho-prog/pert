<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicPlanController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get()
        );
    }
}
