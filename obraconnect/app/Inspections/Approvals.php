<?php

namespace App\Inspections;

use App\Models\Inspection;
use App\Models\InspectionEvent;
use Illuminate\Support\Collection;

/**
 * Aprovações em vários passos: uma inspeção pode precisar de 1 a 3 aprovações de pessoas
 * diferentes. Contam as dadas depois da última submissão (devolver e voltar a submeter começa
 * de novo). Vêm do próprio histórico encadeado, por isso não há nada a sincronizar.
 */
final class Approvals
{
    public const MAX = 3;

    public const ACTIONS = ['approve', 'approve_with_reservations'];

    /** @return Collection<int, InspectionEvent> */
    public static function currentRound(Inspection $inspection): Collection
    {
        $events = $inspection->relationLoaded('events')
            ? $inspection->events
            : InspectionEvent::query()->where('inspection_id', $inspection->id)->orderBy('sequence')->get();

        $lastSubmit = $events->last(fn (InspectionEvent $e) => $e->action === 'submit');
        if ($lastSubmit === null) {
            return collect();
        }

        return $events
            ->filter(fn (InspectionEvent $e) => $e->sequence > $lastSubmit->sequence && in_array($e->action, self::ACTIONS, true))
            ->values();
    }

    public static function hasApproved(Inspection $inspection, int $userId): bool
    {
        return self::currentRound($inspection)->contains(fn (InspectionEvent $e) => $e->user_id === $userId);
    }
}
