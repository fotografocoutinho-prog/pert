<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Em preparação',
            self::Active => 'Em curso',
            self::Suspended => 'Suspensa',
            self::Completed => 'Concluída',
            self::Archived => 'Arquivada',
        };
    }

    /** Conta para o limite de obras ativas do plano. */
    public function countsAsActive(): bool
    {
        return in_array($this, [self::Planning, self::Active, self::Suspended], true);
    }

    /** Concluir, arquivar e reabrir exigem projects.archive; o resto, projects.update. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Archived], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->countsAsActive()),
        ));
    }
}
