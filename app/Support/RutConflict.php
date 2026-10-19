<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class RutConflict
{
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $detail = strtolower($exception->errorInfo[2] ?? $exception->getMessage());

        if ($state === '23505') {
            return str_contains($detail, 'usuarios_rut_unique');
        }

        return $state === '23000' && str_contains($detail, 'usuarios.rut');
    }
}
