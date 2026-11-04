<?php

namespace App\Enums;

/**
 * Os 9 estados de uma inspeção. As passagens entre eles estão em App\Inspections\InspectionWorkflow.
 */
enum InspectionStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case ApprovedWithReservations = 'approved_with_reservations';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Requested => 'Pedida',
            self::Scheduled => 'Agendada',
            self::InProgress => 'Em curso',
            self::Submitted => 'Em apreciação',
            self::Approved => 'Aprovada',
            self::ApprovedWithReservations => 'Aprovada com reservas',
            self::Rejected => 'Rejeitada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Decidida ou cancelada: já não muda de estado. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Approved, self::ApprovedWithReservations, self::Rejected, self::Cancelled], true);
    }

    /**
     * Grupos usados no filtro da lista.
     *
     * @return list<self>
     */
    public static function group(string $group): array
    {
        return match ($group) {
            'open' => [self::Draft, self::Requested, self::Scheduled, self::InProgress],
            'review' => [self::Submitted],
            'closed' => [self::Approved, self::ApprovedWithReservations, self::Rejected, self::Cancelled],
            default => self::cases(),
        };
    }
}
