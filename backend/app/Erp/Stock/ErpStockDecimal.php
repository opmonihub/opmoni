<?php

namespace App\Erp\Stock;

use App\Erp\ErpCatalogDecimal;
use App\Erp\Finance\ErpDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpStockDecimal
{
    public static function factor(mixed $value, string $field): string
    {
        Validator::make([$field => $value], [$field => ['required', ...ErpCatalogDecimal::widthRule(14, 8)]])->validate();
        if (bccomp((string) $value, '0', 8) <= 0) {
            throw ValidationException::withMessages([$field => 'Fator deve ser positivo.']);
        }

        return bcadd((string) $value, '0', 8);
    }

    public static function base(string $quantity, string $factor, string $field): string
    {
        $exact = bcmul(ErpDecimal::parse($quantity), $factor, 12);
        $base = bcadd($exact, '0', 4);
        if (bccomp($exact, $base, 12) !== 0) {
            throw ValidationException::withMessages([$field => 'A quantidade base exige mais de quatro casas decimais.']);
        }

        try {
            return ErpDecimal::parse($base);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => 'Quantidade base fora do limite permitido.']);
        }
    }

    public static function slice(string $total, string $quantity, string $cumulative, string $already): string
    {
        return bcsub(bcdiv(bcmul($total, $cumulative, 8), $quantity, 4), $already, 4);
    }

    public static function min(string $left, string $right): string
    {
        return bccomp($left, $right, 4) < 0 ? $left : $right;
    }
}
