<?php

namespace App\Rules;

use App\Support\PortugueseNif;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPortugueseNif implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PortugueseNif::isValid((string) $value)) {
            $fail('O NIF indicado não é válido.');
        }
    }
}
