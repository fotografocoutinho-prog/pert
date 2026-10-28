<?php

namespace App\Support;

/**
 * Validação do Número de Identificação Fiscal português (9 dígitos, módulo 11).
 *
 * Valida apenas a estrutura e o dígito de controlo. Não confirma que o NIF
 * existe nem a quem pertence: isso exige consulta à AT ou a um serviço autorizado.
 */
final class PortugueseNif
{
    /** Prefixos atribuídos (pessoas singulares, coletivas, entidades públicas, etc.). */
    private const VALID_PREFIXES = [
        '1', '2', '3', '5', '6', '8',
        '45', '70', '71', '72', '74', '75', '77', '78', '79', '90', '91', '98', '99',
    ];

    public static function normalize(?string $nif): string
    {
        $nif = preg_replace('/\s+/', '', (string) $nif);

        return str_starts_with(strtoupper($nif), 'PT') ? substr($nif, 2) : $nif;
    }

    public static function isValid(?string $nif): bool
    {
        $nif = self::normalize($nif);

        if (! preg_match('/^\d{9}$/', $nif)) {
            return false;
        }

        if (! self::hasValidPrefix($nif)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }

        $remainder = $sum % 11;
        $check = $remainder < 2 ? 0 : 11 - $remainder;

        return $check === (int) $nif[8];
    }

    private static function hasValidPrefix(string $nif): bool
    {
        foreach (self::VALID_PREFIXES as $prefix) {
            if (str_starts_with($nif, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
