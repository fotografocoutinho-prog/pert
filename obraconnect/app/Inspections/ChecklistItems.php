<?php

namespace App\Inspections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Pontos de uma lista de verificação, tal como chegam nos pedidos (modelos e rascunhos de
 * inspeção): regras de validação e forma guardada.
 */
final class ChecklistItems
{
    public const MAX = 200;

    /** @return array<string, list<mixed>> */
    public static function rules(string $field = 'items', bool $required = true): array
    {
        return [
            $field => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX],
            "{$field}.*.text" => ['required', 'string', 'max:500'],
            "{$field}.*.guidance" => ['nullable', 'string', 'max:500'],
            "{$field}.*.requires_photo" => ['sometimes', 'boolean'],
            "{$field}.*.min_photos" => ['nullable', 'integer', 'between:0,20'],
            "{$field}.*.requires_measure" => ['sometimes', 'boolean'],
            "{$field}.*.measure_unit" => ['nullable', 'string', 'max:20'],
            "{$field}.*.measure_min" => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            "{$field}.*.measure_max" => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(string $field = 'items'): array
    {
        return [
            "{$field}.*.text" => 'ponto a verificar',
            "{$field}.*.guidance" => 'indicações',
            "{$field}.*.min_photos" => 'número de fotografias',
            "{$field}.*.measure_unit" => 'unidade',
            "{$field}.*.measure_min" => 'mínimo',
            "{$field}.*.measure_max" => 'máximo',
        ];
    }

    /**
     * Forma guardada, com as exigências coerentes entre si (sem fotografia exigida não há
     * mínimo; sem medição exigida não há unidade nem limites).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $items, string $field = 'items'): array
    {
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $photo = (bool) ($item['requires_photo'] ?? false);
            $measure = (bool) ($item['requires_measure'] ?? false);
            $min = $measure && isset($item['measure_min']) && $item['measure_min'] !== '' ? (string) $item['measure_min'] : null;
            $max = $measure && isset($item['measure_max']) && $item['measure_max'] !== '' ? (string) $item['measure_max'] : null;

            if ($min !== null && $max !== null && (float) $max < (float) $min) {
                throw ValidationException::withMessages(["{$field}.{$i}.measure_max" => 'O máximo não pode ser inferior ao mínimo.']);
            }

            $rows[] = [
                'sort_order' => $i,
                'text' => trim((string) $item['text']),
                'guidance' => filled($item['guidance'] ?? null) ? trim((string) $item['guidance']) : null,
                'requires_photo' => $photo,
                'min_photos' => $photo ? max(1, (int) ($item['min_photos'] ?? 1)) : 0,
                'requires_measure' => $measure,
                'measure_unit' => $measure && filled($item['measure_unit'] ?? null) ? trim((string) $item['measure_unit']) : null,
                'measure_min' => $min,
                'measure_max' => $max,
            ];
        }

        return $rows;
    }

    /**
     * Para comparar listas (saber se um modelo mudou de facto).
     *
     * @param  iterable<array<string, mixed>|Model>  $items
     * @return list<array<string, mixed>>
     */
    public static function comparable(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $row = $item instanceof Model ? $item->only(['text', 'guidance', 'requires_photo', 'min_photos', 'requires_measure', 'measure_unit', 'measure_min', 'measure_max']) : $item;
            $out[] = [
                'text' => $row['text'],
                'guidance' => $row['guidance'] ?? null,
                'requires_photo' => (bool) $row['requires_photo'],
                'min_photos' => (int) $row['min_photos'],
                'requires_measure' => (bool) $row['requires_measure'],
                'measure_unit' => $row['measure_unit'] ?? null,
                'measure_min' => $row['measure_min'] === null ? null : (float) $row['measure_min'],
                'measure_max' => $row['measure_max'] === null ? null : (float) $row['measure_max'],
            ];
        }

        return $out;
    }

    /**
     * Para respostas JSON: limites e valores como números.
     *
     * @return array<string, mixed>
     */
    public static function present(Model $item): array
    {
        return [
            'id' => $item->id,
            'text' => $item->text,
            'guidance' => $item->guidance,
            'requires_photo' => (bool) $item->requires_photo,
            'min_photos' => (int) $item->min_photos,
            'requires_measure' => (bool) $item->requires_measure,
            'measure_unit' => $item->measure_unit,
            'measure_min' => $item->measure_min === null ? null : (float) $item->measure_min,
            'measure_max' => $item->measure_max === null ? null : (float) $item->measure_max,
        ];
    }
}
