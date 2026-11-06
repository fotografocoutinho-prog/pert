<?php

namespace App\Issues;

use App\Enums\IssueStatus as S;

/**
 * Passagens de estado de uma ocorrência ou não conformidade. Quem resolve (issues.resolve) e quem
 * valida (issues.validate) são permissões diferentes; validar fecha, reabrir devolve a "aberta".
 */
final class IssueWorkflow
{
    /** Quem tem qualquer uma destas na obra comenta e junta fotografias. */
    public const PARTICIPATE = ['issues.create', 'issues.resolve', 'issues.validate'];

    /**
     * @return array<string, array{label: string, from: list<S>, to: S, permission: string, reason: bool}>
     */
    public static function actions(): array
    {
        return [
            'start' => ['label' => 'Começar a resolver', 'from' => [S::Open], 'to' => S::InProgress, 'permission' => 'issues.resolve', 'reason' => false],
            'resolve' => ['label' => 'Marcar como resolvida', 'from' => [S::Open, S::InProgress], 'to' => S::Resolved, 'permission' => 'issues.resolve', 'reason' => true],
            'close' => ['label' => 'Validar e fechar', 'from' => [S::Resolved], 'to' => S::Closed, 'permission' => 'issues.validate', 'reason' => false],
            'reopen' => ['label' => 'Reabrir', 'from' => [S::Resolved, S::Closed], 'to' => S::Open, 'permission' => 'issues.validate', 'reason' => true],
            'cancel' => ['label' => 'Anular', 'from' => [S::Open, S::InProgress], 'to' => S::Cancelled, 'permission' => 'issues.validate', 'reason' => true],
        ];
    }
}
