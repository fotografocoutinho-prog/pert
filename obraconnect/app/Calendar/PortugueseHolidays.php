<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Feriados obrigatórios em Portugal: art. 234.º, n.º 1, do Código do Trabalho, com os quatro
 * repostos pela Lei n.º 8/2016 (Corpo de Deus, 5 de outubro, 1 de novembro e 1 de dezembro).
 * Por isso só se calculam a partir de 2016. O Carnaval é facultativo (art. 235.º) e o feriado
 * municipal depende do concelho: ficam por conta de cada empresa.
 */
final class PortugueseHolidays
{
    public const FIRST_YEAR = 2016;

    public const LAST_YEAR = 2099;

    /** Domingo de Páscoa no calendário gregoriano (algoritmo de Meeus/Jones/Butcher). */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;

        return CarbonImmutable::create($year, intdiv($n, 31), $n % 31 + 1);
    }

    /**
     * Os 13 feriados obrigatórios do ano. Se dois calharem no mesmo dia, os nomes juntam-se.
     *
     * @return array<string, string> 'Y-m-d' => nome, por ordem de data
     */
    public static function national(int $year): array
    {
        self::assertSupported($year);
        $easter = self::easter($year);

        $list = [
            ["{$year}-01-01", 'Ano Novo'],
            [$easter->subDays(2)->toDateString(), 'Sexta-feira Santa'],
            [$easter->toDateString(), 'Domingo de Páscoa'],
            ["{$year}-04-25", 'Dia da Liberdade'],
            ["{$year}-05-01", 'Dia do Trabalhador'],
            [$easter->addDays(60)->toDateString(), 'Corpo de Deus'],
            ["{$year}-06-10", 'Dia de Portugal, de Camões e das Comunidades Portuguesas'],
            ["{$year}-08-15", 'Assunção de Nossa Senhora'],
            ["{$year}-10-05", 'Implantação da República'],
            ["{$year}-11-01", 'Dia de Todos os Santos'],
            ["{$year}-12-01", 'Restauração da Independência'],
            ["{$year}-12-08", 'Imaculada Conceição'],
            ["{$year}-12-25", 'Natal'],
        ];

        $days = [];
        foreach ($list as [$date, $name]) {
            $days[$date] = isset($days[$date]) ? "{$days[$date]} e {$name}" : $name;
        }
        ksort($days);

        return $days;
    }

    /** Terça-feira de Carnaval (47 dias antes da Páscoa). Feriado facultativo. */
    public static function carnival(int $year): string
    {
        self::assertSupported($year);

        return self::easter($year)->subDays(47)->toDateString();
    }

    private static function assertSupported(int $year): void
    {
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            throw new InvalidArgumentException('Feriados calculados só entre '.self::FIRST_YEAR.' e '.self::LAST_YEAR.'.');
        }
    }
}
