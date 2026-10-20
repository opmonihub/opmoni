<?php

namespace Tests\Unit;

use App\Erp\ErpLabelBarcodeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ErpLabelBarcodeTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    #[DataProvider('validBarcodes')]
    public function test_full_source_code_is_accepted_without_normalizing_it(string $type, string $barcode): void
    {
        $this->assertTrue(ErpLabelBarcodeValidator::isValid($type, $barcode));
    }

    /** @return array<string, array{string, string}> */
    public static function validBarcodes(): array
    {
        return [
            'code 128 leading zero' => ['C128', '00123'],
            'code 39 uppercase' => ['C39', 'ABC-123'],
            'ean 13' => ['EAN13', '4006381333931'],
            'ean 8' => ['EAN8', '96385074'],
            'upc a' => ['UPCA', '036000291452'],
            'upc e' => ['UPCE', '04252614'],
        ];
    }

    #[DataProvider('invalidBarcodes')]
    public function test_incomplete_or_renderer_normalized_codes_are_rejected(string $type, string $barcode): void
    {
        $this->assertFalse(ErpLabelBarcodeValidator::isValid($type, $barcode));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidBarcodes(): array
    {
        return [
            'unknown format' => ['QR', '00123'],
            'empty source' => ['C128', ''],
            'code 39 lowercase' => ['C39', 'abc-123'],
            'ean 13 short payload' => ['EAN13', '400638133393'],
            'ean 13 wrong check digit' => ['EAN13', '4006381333932'],
            'ean 8 wrong check digit' => ['EAN8', '96385075'],
            'upc a wrong check digit' => ['UPCA', '036000291453'],
            'upc e wrong check digit' => ['UPCE', '04210005'],
        ];
    }
}
