<?php

namespace Tests\Unit;

use App\Erp\Commercial\OfficeErpMoney;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfficeErpMoneyTest extends TestCase
{
    public function test_exact_money_and_large_values(): void
    {
        self::assertSame('60.00', OfficeErpMoney::subtract('100.00', '40.00'));
        self::assertSame('9007199254740993.01', OfficeErpMoney::canonical('9007199254740993.01'));
        self::assertSame('100.00', OfficeErpMoney::add('60.00', '40.00'));
        self::assertSame('0.00', OfficeErpMoney::canonical('0'));
        self::assertSame('10.00', OfficeErpMoney::canonical('10.0'));
        self::assertSame(1, OfficeErpMoney::compare('100.00', '40.00'));
        self::assertSame(0, OfficeErpMoney::compare('40.00', '40.00'));
        self::assertSame(-1, OfficeErpMoney::compare('40.00', '100.00'));
    }

    public function test_arithmetic_supports_signed_report_movements_without_float_rounding(): void
    {
        self::assertSame('-20.00', OfficeErpMoney::subtract('80.00', '100.00'));
        self::assertSame('40.00', OfficeErpMoney::add('-20.00', '60.00'));
        self::assertSame('-80.00', OfficeErpMoney::subtract('-20.00', '60.00'));
        self::assertSame('9007199254740993.01', OfficeErpMoney::add('9007199254740993.00', '0.01'));
    }

    public function test_rejects_excess_precision(): void
    {
        $this->expectException(ValidationException::class);
        OfficeErpMoney::canonical('1.001');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCanonicalValues(): array
    {
        return [
            'float' => [1.0],
            'integer' => [1],
            'scientific notation' => ['1e3'],
            'leading plus' => ['+1.00'],
            'negative' => ['-1.00'],
            'too many whole digits' => ['1234567890123456789.00'],
            'leading decimal point' => ['.50'],
            'trailing decimal point' => ['1.'],
            'ambiguous comma' => ['1,00'],
            'blank' => ['  '],
        ];
    }

    #[DataProvider('invalidCanonicalValues')]
    public function test_canonical_rejects_non_decimal_strings_and_numeric_types(mixed $value): void
    {
        try {
            OfficeErpMoney::canonical($value, 'price');
            self::fail('Expected canonical money validation to reject the value.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('price', $exception->errors());
        }
    }

    public function test_arithmetic_rejects_results_that_overflow_the_supported_scale(): void
    {
        $this->expectException(ValidationException::class);
        OfficeErpMoney::add('999999999999999999.99', '0.01');
    }
}
