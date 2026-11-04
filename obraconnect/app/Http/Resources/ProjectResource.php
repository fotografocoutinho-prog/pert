<?php

namespace App\Http\Resources;

use App\Access\AccessResolver;
use App\Access\PermissionRegistry;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name, 'type' => $this->client->type->value]
                : null),
            'owner_name' => $this->owner_name,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'contract_value_cents' => $this->contract_value_cents,
            'starts_on' => $this->starts_on?->toDateString(),
            'planned_end_on' => $this->planned_end_on?->toDateString(),
            'work_calendar_id' => $this->work_calendar_id,
            'work_calendar' => $this->whenLoaded('workCalendar', fn () => $this->workCalendar
                ? ['id' => $this->workCalendar->id, 'name' => $this->workCalendar->name]
                : null),
            'cost_center_id' => $this->cost_center_id,
            'cost_center' => $this->whenLoaded('costCenter', fn () => $this->costCenter
                ? ['id' => $this->costCenter->id, 'code' => $this->costCenter->code, 'name' => $this->costCenter->name]
                : null),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'team_count' => $this->whenCounted('members'),
            'created_at' => $this->created_at?->toIso8601String(),
            // O que quem pede pode fazer nesta obra (a interface só mostra; o servidor decide).
            'permissions' => $this->viewerPermissions(),
        ];
    }

    /** @return list<string> */
    private function viewerPermissions(): array
    {
        $context = app(TenantContext::class);

        if ($context->isSupportSession()) {
            return array_values(array_filter(
                PermissionRegistry::keys(),
                fn (string $key) => PermissionRegistry::isProjectScoped($key) && str_ends_with($key, '.view'),
            ));
        }

        $membership = $context->membership();

        return $membership ? app(AccessResolver::class)->permissionsForProject($membership, $this->resource) : [];
    }
}
