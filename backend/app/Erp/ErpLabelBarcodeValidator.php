<?php

namespace App\Erp;

final class ErpLabelBarcodeValidator
{
    public static function isValid(string $type, string $barcode): bool
    {
        if ($barcode === '') {
            return false;
        }

        return match ($type) {
            'C128' => preg_match('/\A[\x20-\x7E]+\z/D', $barcode) === 1,
            'C39' => preg_match('~\A[A-Z0-9 .$/+%-]+\z~D', $barcode) === 1,
            'EAN13' => self::hasCheckDigit($barcode, 13, 1),
            'EAN8' => self::hasCheckDigit($barcode, 8, 3),
            'UPCA' => self::hasCheckDigit($barcode, 12, 3),
            'UPCE' => self::isValidUpce($barcode),
            default => false,
        };
    }

    private static function hasCheckDigit(string $barcode, int $length, int $leftWeight): bool
    {
        if (strlen($barcode) !== $length || preg_match('/\A\d+\z/D', $barcode) !== 1) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < $length - 1; $index++) {
            $digit = (int) $barcode[$index];
            $weight = $index % 2 === 0 ? $leftWeight : ($leftWeight === 1 ? 3 : 1);
            $sum += $digit * $weight;
        }

        return (string) ((10 - ($sum % 10)) % 10) === $barcode[$length - 1];
    }

    private static function isValidUpce(string $barcode): bool
    {
        if (strlen($barcode) !== 8 || preg_match('/\A[01]\d{7}\z/D', $barcode) !== 1) {
            return false;
        }

        $numberSystem = $barcode[0];
        $data = substr($barcode, 1, 6);
        $last = $data[5];
        $expanded = match ($last) {
            '0', '1', '2' => $numberSystem.$data[0].$data[1].$last.'0000'.$data[2].$data[3].$data[4],
            '3' => $numberSystem.$data[0].$data[1].$data[2].'00000'.$data[3].$data[4],
            '4' => $numberSystem.$data[0].$data[1].$data[2].$data[3].'00000'.$data[4],
            default => $numberSystem.substr($data, 0, 5).'0000'.$last,
        };

        return self::hasCheckDigit($expanded.$barcode[7], 12, 3);
    }
}
