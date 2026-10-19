<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ChileanRut
{
    public static function compact(string $rut): string
    {
        return Str::upper((string) preg_replace('/[\s.\-]+/u', '', trim($rut)));
    }

    public static function normalize(string $rut): string
    {
        $compact = self::compact($rut);

        return substr($compact, 0, -1).'-'.substr($compact, -1);
    }

    public static function format(string $rut): string
    {
        $compact = self::compact($rut);
        if (! preg_match('/^\d{1,8}[0-9K]$/', $compact)) {
            return $rut;
        }

        $body = substr($compact, 0, -1);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $body).'-'.substr($compact, -1);
    }

    public static function isValid(string $rut): bool
    {
        $compact = self::compact($rut);
        if (! preg_match('/^\d{1,8}[0-9K]$/', $compact)) {
            return false;
        }

        $body = substr($compact, 0, -1);
        $providedCheckDigit = substr($compact, -1);
        $sum = 0;
        $factor = 2;

        for ($index = strlen($body) - 1; $index >= 0; $index--) {
            $sum += (int) $body[$index] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $remainder = 11 - ($sum % 11);
        $expectedCheckDigit = match ($remainder) {
            11 => '0',
            10 => 'K',
            default => (string) $remainder,
        };

        return hash_equals($expectedCheckDigit, $providedCheckDigit);
    }
}
