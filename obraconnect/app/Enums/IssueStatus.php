<?php

namespace App\Enums;

/** Estados de uma ocorrência ou não conformidade. As passagens estão em App\Issues\IssueWorkflow. */
enum IssueStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::InProgress => 'Em resolução',
            self::Resolved => 'Resolvida, por validar',
            self::Closed => 'Fechada',
            self::Cancelled => 'Anulada',
        };
    }

    /** Ainda por resolver: conta para prazos e escalonamento. */
    public function isPending(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /** @return list<self> */
    public static function group(string $group): array
    {
        return match ($group) {
            'open' => [self::Open, self::InProgress],
            'review' => [self::Resolved],
            'closed' => [self::Closed, self::Cancelled],
            default => self::cases(),
        };
    }
}
