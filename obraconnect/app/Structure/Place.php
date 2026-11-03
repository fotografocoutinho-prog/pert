<?php

namespace App\Structure;

use App\Models\Project;
use Illuminate\Validation\Rule;

/**
 * "Onde" de uma linha do diário, fotografia ou documento: uma localização da obra
 * e/ou um elemento construtivo. Com elemento, a localização é sempre a dele.
 */
final class Place
{
    /** @return array<string, list<mixed>> */
    public static function rules(Project $project, string $prefix = ''): array
    {
        return [
            "{$prefix}location_id" => ['sometimes', 'nullable', 'integer', Rule::exists('project_locations', 'id')->where('project_id', $project->id)],
            "{$prefix}element_id" => ['sometimes', 'nullable', 'integer', Rule::exists('construction_elements', 'id')->where('project_id', $project->id)],
        ];
    }

    /**
     * Preenche location_id a partir do elemento, quando há elemento.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeMany(Project $project, array $rows): array
    {
        $elementIds = array_values(array_unique(array_filter(array_column($rows, 'element_id'))));
        $locationOf = $elementIds === [] ? collect() : $project->elements()->whereKey($elementIds)->pluck('location_id', 'id');

        return array_map(function (array $row) use ($locationOf) {
            if (! empty($row['element_id'])) {
                $row['location_id'] = (int) $locationOf[$row['element_id']];
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(Project $project, array $data): array
    {
        return self::normalizeMany($project, [$data])[0];
    }
}
