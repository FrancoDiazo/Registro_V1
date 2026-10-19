<?php

namespace App\Rules;

use App\Support\ChileanRut;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidChileanRut implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ChileanRut::isValid($value)) {
            $fail('Ingresa un RUT válido, incluyendo su dígito verificador.');
        }
    }
}
