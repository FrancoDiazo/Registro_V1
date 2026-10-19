<?php

namespace Tests\Unit;

use App\Support\RutConflict;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class RutConflictTest extends TestCase
{
    public function test_it_detects_only_unique_violations_for_the_account_rut_constraint(): void
    {
        $rutViolation = $this->queryException('23000', 'UNIQUE constraint failed: usuarios.rut');
        $otherViolation = $this->queryException('23000', 'UNIQUE constraint failed: usuarios.email');
        $postgresRutViolation = $this->queryException('23505', 'duplicate key violates unique constraint "usuarios_rut_unique"');
        $postgresOtherViolation = $this->queryException('23505', 'duplicate key violates unique constraint "usuarios_email_unique"');

        $this->assertTrue(RutConflict::isUniqueViolation($rutViolation));
        $this->assertFalse(RutConflict::isUniqueViolation($otherViolation));
        $this->assertTrue(RutConflict::isUniqueViolation($postgresRutViolation));
        $this->assertFalse(RutConflict::isUniqueViolation($postgresOtherViolation));
    }

    private function queryException(string $state, string $detail): QueryException
    {
        $previous = new PDOException($detail);
        $previous->errorInfo = [$state, 19, $detail];

        return new QueryException('sqlite', 'insert into usuarios', [], $previous);
    }
}
