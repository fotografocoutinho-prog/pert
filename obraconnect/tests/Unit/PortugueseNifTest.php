<?php

namespace Tests\Unit;

use App\Support\PortugueseNif;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PortugueseNifTest extends TestCase
{
    public static function validNifs(): array
    {
        // Gerados para passar o dígito de controlo; não correspondem a entidades conhecidas.
        return [['123456789'], ['501234560'], ['PT 123 456 789'], ['999999990']];
    }

    public static function invalidNifs(): array
    {
        return [['123456780'], ['12345678'], ['1234567890'], ['abcdefghi'], ['400000000'], [''], [null]];
    }

    #[DataProvider('validNifs')]
    public function test_valid_nifs(string $nif): void
    {
        $this->assertTrue(PortugueseNif::isValid($nif));
    }

    #[DataProvider('invalidNifs')]
    public function test_invalid_nifs(?string $nif): void
    {
        $this->assertFalse(PortugueseNif::isValid($nif));
    }

    public function test_normalize_removes_prefix_and_spaces(): void
    {
        $this->assertSame('123456789', PortugueseNif::normalize('PT 123 456 789'));
    }
}
