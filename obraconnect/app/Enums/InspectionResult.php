<?php

namespace App\Enums;

/** Resultado de um ponto da inspeção. Sem resultado = ainda por verificar. */
enum InspectionResult: string
{
    case Ok = 'ok';
    case Nok = 'nok';
    case NotApplicable = 'na';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Conforme',
            self::Nok => 'Não conforme',
            self::NotApplicable => 'Não aplicável',
        };
    }
}
