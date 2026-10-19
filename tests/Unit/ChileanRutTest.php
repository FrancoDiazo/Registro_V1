<?php

namespace Tests\Unit;

use App\Support\ChileanRut;
use PHPUnit\Framework\TestCase;

class ChileanRutTest extends TestCase
{
    public function test_it_validates_and_normalizes_ruts_with_optional_separators(): void
    {
        $this->assertTrue(ChileanRut::isValid('12.345.678-5'));
        $this->assertTrue(ChileanRut::isValid('123456785'));
        $this->assertSame('12345678-5', ChileanRut::normalize('12.345.678-5'));
        $this->assertSame('12.345.678-5', ChileanRut::format('12345678-5'));
    }

    public function test_it_rejects_invalid_check_digits_and_characters(): void
    {
        $this->assertFalse(ChileanRut::isValid('12.345.678-4'));
        $this->assertFalse(ChileanRut::isValid('12.345.678-X'));
    }
}
