<?php

namespace App\Inspections;

use App\Enums\InspectionResult;
use App\Models\Inspection;
use App\Models\InspectionItem;
use Illuminate\Support\Collection;

/**
 * Índice de suficiência das evidências de uma inspeção (0-100), explicado critério a critério.
 *
 * Os critérios obrigatórios impedem a submissão enquanto faltarem; os recomendados só baixam o
 * índice, para quem decide ver o que falta. O índice nunca aprova nada: é informação para a
 * decisão, que é sempre de uma pessoa.
 */
final class EvidenceIndex
{
    /**
     * @return array{score: int|null, can_submit: bool, criteria: list<array{key: string, label: string, required: bool, met: int, total: int, missing: list<int>}>}
     */
    public static function evaluate(Inspection $inspection): array
    {
        // Número que cada ponto mostra na lista (1, 2, 3...).
        $items = $inspection->items->values();
        $numbered = $items->mapWithKeys(fn (InspectionItem $item, int $i) => [$i + 1 => $item]);
        $applicable = $numbered->filter(fn (InspectionItem $item) => $item->result !== InspectionResult::NotApplicable);
        $nok = $numbered->filter(fn (InspectionItem $item) => $item->result === InspectionResult::Nok);
        $photos = fn (InspectionItem $item) => $item->relationLoaded('photos') ? $item->photos->count() : $item->photos()->count();

        $criteria = [
            self::criterion('answered', 'Pontos verificados', true, $numbered, fn ($item) => $item->result !== null),
            self::criterion('photos', 'Fotografias exigidas', true,
                $applicable->filter(fn ($item) => $item->photosRequired() > 0),
                fn ($item) => $photos($item) >= $item->photosRequired()),
            self::criterion('measures', 'Medições exigidas', true,
                $applicable->filter(fn ($item) => $item->requires_measure),
                fn ($item) => $item->measure_value !== null),
            self::criterion('nok_notes', 'Não conformidades descritas', true, $nok, fn ($item) => filled($item->notes)),
            self::criterion('nok_photos', 'Não conformidades com fotografia', false, $nok, fn ($item) => $photos($item) > 0),
            self::criterion('tolerance', 'Medições conformes dentro dos limites', false,
                $numbered->filter(fn ($item) => $item->result === InspectionResult::Ok && $item->measure_value !== null
                    && ($item->measure_min !== null || $item->measure_max !== null)),
                fn ($item) => ! $item->isOutOfTolerance()),
        ];
        if ($inspection->remote) {
            $criteria[] = self::criterion('remote_photos', 'Inspeção remota: fotografia em cada ponto aplicável', false,
                $applicable, fn ($item) => $photos($item) > 0);
        }

        $criteria = array_values(array_filter($criteria, fn ($c) => $c['total'] > 0));
        $total = array_sum(array_column($criteria, 'total'));
        $met = array_sum(array_column($criteria, 'met'));

        return [
            'score' => $total > 0 ? (int) floor($met * 100 / $total) : null,
            'can_submit' => $items->isNotEmpty() && collect($criteria)->every(fn ($c) => ! $c['required'] || $c['met'] === $c['total']),
            'criteria' => $criteria,
        ];
    }

    /**
     * @param  Collection<int, InspectionItem>  $items  número => ponto
     * @return array{key: string, label: string, required: bool, met: int, total: int, missing: list<int>}
     */
    private static function criterion(string $key, string $label, bool $required, $items, callable $ok): array
    {
        $missing = $items->reject(fn ($item) => $ok($item))->keys()->all();

        return [
            'key' => $key,
            'label' => $label,
            'required' => $required,
            'met' => $items->count() - count($missing),
            'total' => $items->count(),
            'missing' => array_values($missing),
        ];
    }
}
