<?php

namespace App\Enums;

/**
 * Linhas de um registo do diário. Todas usam os mesmos campos:
 * descrição, quantidade + unidade e empresa (subempreiteiro, fornecedor, entidade).
 */
enum DiaryItemKind: string
{
    case Workforce = 'workforce';
    case Equipment = 'equipment';
    case Material = 'material';
    case Incident = 'incident';
    case Visit = 'visit';

    public function label(): string
    {
        return match ($this) {
            self::Workforce => 'Mão de obra',
            self::Equipment => 'Equipamentos',
            self::Material => 'Materiais recebidos',
            self::Incident => 'Ocorrências',
            self::Visit => 'Visitas',
        };
    }
}
