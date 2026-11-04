<?php

namespace App\Inspections;

use App\Enums\InspectionStatus as S;

/**
 * Passagens de estado de uma inspeção: de onde se parte, onde se chega, quem pode e se é
 * preciso dizer porquê. Nenhuma inspeção é aprovada sem uma decisão explícita de alguém
 * com inspections.approve.
 */
final class InspectionWorkflow
{
    /** Quem tem qualquer uma destas permissões na obra vê as inspeções dela. */
    public const VIEW_PERMISSIONS = ['inspections.request', 'inspections.perform', 'inspections.approve'];

    /**
     * @return array<string, array{label: string, done: string, from: list<S>, to: S, permissions: list<string>, reason: bool}>
     */
    public static function actions(): array
    {
        return [
            'request' => [
                'label' => 'Pedir inspeção', 'done' => 'Pedida',
                'from' => [S::Draft], 'to' => S::Requested,
                'permissions' => ['inspections.request'], 'reason' => false,
            ],
            'schedule' => [
                'label' => 'Agendar', 'done' => 'Agendada',
                'from' => [S::Requested, S::Scheduled], 'to' => S::Scheduled,
                'permissions' => ['inspections.perform'], 'reason' => false,
            ],
            'start' => [
                'label' => 'Começar a inspeção', 'done' => 'Iniciada',
                'from' => [S::Requested, S::Scheduled], 'to' => S::InProgress,
                'permissions' => ['inspections.perform'], 'reason' => false,
            ],
            'submit' => [
                'label' => 'Submeter para apreciação', 'done' => 'Submetida para apreciação',
                'from' => [S::InProgress], 'to' => S::Submitted,
                'permissions' => ['inspections.perform'], 'reason' => false,
            ],
            'return' => [
                'label' => 'Devolver para completar', 'done' => 'Devolvida para completar',
                'from' => [S::Submitted], 'to' => S::InProgress,
                'permissions' => ['inspections.approve'], 'reason' => true,
            ],
            'approve' => [
                'label' => 'Aprovar', 'done' => 'Aprovada',
                'from' => [S::Submitted], 'to' => S::Approved,
                'permissions' => ['inspections.approve'], 'reason' => false,
            ],
            'approve_with_reservations' => [
                'label' => 'Aprovar com reservas', 'done' => 'Aprovada com reservas',
                'from' => [S::Submitted], 'to' => S::ApprovedWithReservations,
                'permissions' => ['inspections.approve'], 'reason' => true,
            ],
            'reject' => [
                'label' => 'Rejeitar', 'done' => 'Rejeitada',
                'from' => [S::Submitted], 'to' => S::Rejected,
                'permissions' => ['inspections.approve'], 'reason' => true,
            ],
            'cancel' => [
                'label' => 'Cancelar inspeção', 'done' => 'Cancelada',
                'from' => [S::Requested, S::Scheduled, S::InProgress], 'to' => S::Cancelled,
                'permissions' => ['inspections.request', 'inspections.approve'], 'reason' => true,
            ],
        ];
    }

    /** Rótulos das linhas do histórico (inclui "created", que não é uma passagem). */
    public static function eventLabel(string $action): string
    {
        return match ($action) {
            'created' => 'Criada',
            'reinspection' => 'Pedida reinspeção',
            default => self::actions()[$action]['done'] ?? $action,
        };
    }

    public static function isDecision(string $action): bool
    {
        return in_array($action, ['approve', 'approve_with_reservations', 'reject'], true);
    }
}
