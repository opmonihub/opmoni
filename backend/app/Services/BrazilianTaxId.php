<?php

namespace App\Services;

/**
 * Documento fiscal tratado como texto em todas as camadas.
 *
 * A RFB IN 2.119/2022 tornou o CNPJ alfanumérico: os doze primeiros caracteres
 * aceitam letras e cada caractere vale `ord($char) - 48` no cálculo do dígito
 * verificador (`'A'` vale 17, `'B'` 18, `'C'` 19). Por isso `normalize`
 * preserva as letras em maiúsculo, `isValidCnpj` exige doze caracteres
 * alfanuméricos mais dois dígitos, e o CPF — que nunca foi alfanumérico —
 * continua exigindo `ctype_digit`.
 */
final class BrazilianTaxId
{
    public function normalize(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '');
    }

    public function isValidCpf(string $value): bool
    {
        $digits = $this->normalize($value);

        return strlen($digits) === 11
            && ctype_digit($digits)
            && ! preg_match('/^(\d)\1+$/', $digits)
            && $this->digit($digits, 9, range(10, 2)) === (int) $digits[9]
            && $this->digit($digits, 10, range(11, 2)) === (int) $digits[10];
    }

    public function isValidCnpj(string $value): bool
    {
        $digits = $this->normalize($value);

        return preg_match('/^[A-Z0-9]{12}[0-9]{2}$/D', $digits) === 1
            && ! preg_match('/^([A-Z0-9])\1+$/', $digits)
            && $this->digit($digits, 12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === (int) $digits[12]
            && $this->digit($digits, 13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === (int) $digits[13];
    }

    /** @param list<int> $weights */
    private function digit(string $digits, int $length, array $weights): int
    {
        $sum = 0;

        for ($index = 0; $index < $length; $index++) {
            $sum += (ord($digits[$index]) - 48) * $weights[$index];
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
