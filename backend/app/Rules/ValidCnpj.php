<?php

namespace App\Rules;

use App\Services\BrazilianTaxId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCnpj implements ValidationRule
{
    public function __construct(private readonly BrazilianTaxId $taxId = new BrazilianTaxId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->taxId->isValidCnpj($value)) {
            $fail('O CNPJ informado é inválido.');
        }
    }
}
