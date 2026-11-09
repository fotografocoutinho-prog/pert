<?php

namespace App\Forecasting;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * As razões de uma previsão: frases curtas em português, com os números já escritos, e um nível
 * (danger, warning, info, good) para a interface. Opcionalmente apontam para o separador da obra
 * onde se resolve o assunto.
 */
final class Explain
{
    private const ORDER = ['danger' => 0, 'warning' => 1, 'info' => 2, 'good' => 3];

    /** @return array{level: string, text: string, tab: string|null} */
    public static function reason(string $level, string $text, ?string $tab = null): array
    {
        return ['level' => $level, 'text' => $text, 'tab' => $tab];
    }

    /**
     * Mais graves primeiro, mantendo a ordem dentro de cada nível.
     *
     * @param  list<array{level: string, text: string, tab: string|null}>  $reasons
     * @return list<array{level: string, text: string, tab: string|null}>
     */
    public static function sort(array $reasons): array
    {
        $indexed = array_map(fn ($r, $i) => [$r, $i], $reasons, array_keys($reasons));
        usort($indexed, fn ($a, $b) => [self::ORDER[$a[0]['level']] ?? 9, $a[1]] <=> [self::ORDER[$b[0]['level']] ?? 9, $b[1]]);

        return array_map(fn ($pair) => $pair[0], $indexed);
    }

    /** 1 234,50 € (espaço inquebrável nos milhares e antes do símbolo). */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{00A0}")."\u{00A0}€";
    }

    public static function date(CarbonInterface|string|null $date): string
    {
        return $date === null ? '—' : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    public static function pct(float $value, int $digits = 0): string
    {
        return number_format($value, $digits, ',', "\u{00A0}")."\u{00A0}%";
    }

    /** Sem zeros à direita ("12,5"), a não ser que se peçam as casas todas ("1,20"). */
    public static function number(float $value, int $digits = 2, bool $trim = true): string
    {
        $text = number_format($value, $digits, ',', "\u{00A0}");

        return $trim && str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** "1 dia" / "3 dias" (e "dias úteis" quando se pede). */
    public static function days(int $n, bool $working = false): string
    {
        $n = abs($n);

        return $n.' '.($n === 1 ? 'dia' : 'dias').($working ? ($n === 1 ? ' útil' : ' úteis') : '');
    }
}
