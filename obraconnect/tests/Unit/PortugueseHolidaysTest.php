<?php

namespace Tests\Unit;

use App\Calendar\PortugueseHolidays;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PortugueseHolidaysTest extends TestCase
{
    public function test_easter_sunday(): void
    {
        $this->assertSame('2024-03-31', PortugueseHolidays::easter(2024)->toDateString());
        $this->assertSame('2025-04-20', PortugueseHolidays::easter(2025)->toDateString());
        $this->assertSame('2026-04-05', PortugueseHolidays::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', PortugueseHolidays::easter(2027)->toDateString());
        $this->assertSame('2038-04-25', PortugueseHolidays::easter(2038)->toDateString());
    }

    public function test_thirteen_national_holidays_with_movable_ones(): void
    {
        $days = PortugueseHolidays::national(2026);

        $this->assertCount(13, $days);
        $this->assertSame('2026-01-01', array_key_first($days));
        $this->assertSame('Ano Novo', $days['2026-01-01']);
        $this->assertSame('Sexta-feira Santa', $days['2026-04-03']);
        $this->assertSame('Domingo de Páscoa', $days['2026-04-05']);
        $this->assertSame('Corpo de Deus', $days['2026-06-04']);
        $this->assertSame('Natal', $days['2026-12-25']);
        // Os quatro repostos pela Lei n.º 8/2016.
        foreach (['2026-06-04', '2026-10-05', '2026-11-01', '2026-12-01'] as $date) {
            $this->assertArrayHasKey($date, $days);
        }
        $this->assertSame('2026-02-17', PortugueseHolidays::carnival(2026));
    }

    public function test_coinciding_holidays_are_joined(): void
    {
        $days = PortugueseHolidays::national(2038);

        $this->assertCount(12, $days);
        $this->assertSame('Domingo de Páscoa e Dia da Liberdade', $days['2038-04-25']);
    }

    public function test_years_before_2016_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PortugueseHolidays::national(2015);
    }
}
