<?php

namespace App\Enums;

/** Dia sem trabalho num calendário. Todos contam como dia não útil; o tipo diz porquê. */
enum HolidayKind: string
{
    case National = 'national';
    case Optional = 'optional';
    case Municipal = 'municipal';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::National => 'Feriado nacional',
            self::Optional => 'Feriado facultativo',
            self::Municipal => 'Feriado municipal',
            self::Company => 'Paragem da empresa',
        };
    }
}
