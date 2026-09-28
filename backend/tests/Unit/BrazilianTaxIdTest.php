<?php

namespace Tests\Unit;

use App\Services\BrazilianTaxId;
use PHPUnit\Framework\TestCase;

class BrazilianTaxIdTest extends TestCase
{
    public function test_normalizes_punctuation(): void
    {
        $service = new BrazilianTaxId;

        $this->assertSame('27865757000102', $service->normalize('27.865.757/0001-02'));
        $this->assertSame('52998224725', $service->normalize('529.982.247-25'));
    }

    public function test_validates_cpf_and_rejects_repeated_digits(): void
    {
        $service = new BrazilianTaxId;

        $this->assertTrue($service->isValidCpf('52998224725'));
        $this->assertFalse($service->isValidCpf('52998224724'));
        $this->assertFalse($service->isValidCpf('11111111111'));
    }

    public function test_validates_cnpj_and_rejects_repeated_digits(): void
    {
        $service = new BrazilianTaxId;

        $this->assertTrue($service->isValidCnpj('27865757000102'));
        $this->assertFalse($service->isValidCnpj('27865757000103'));
        $this->assertFalse($service->isValidCnpj('00000000000000'));
    }

    public function test_aceita_cnpj_alfanumerico_com_digito_verificador_oficial(): void
    {
        $service = new BrazilianTaxId;
        $base = '12ABC3450001';

        // Dígito verificador alfanumérico oficial (RFB IN 2.119/2022): cada caractere
        // vale `ord($char) - 48` — 'A' vale 17, 'B' 18, 'C' 19 — e o resto segue módulo 11.
        $digit = static function (string $number, array $weights): int {
            $sum = 0;
            foreach (str_split($number) as $index => $char) {
                $sum += (ord($char) - 48) * $weights[$index];
            }

            return ($sum % 11) < 2 ? 0 : 11 - ($sum % 11);
        };
        $first = $digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $valid = $base.$first.$digit($base.$first, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        $this->assertSame('12ABC345000188', $valid);
        $this->assertSame('12ABC345000188', $service->normalize('12.ABC.345/0001-88'));
        $this->assertTrue($service->isValidCnpj($valid));
        $this->assertFalse($service->isValidCnpj(substr($valid, 0, -1).(((int) substr($valid, -1) + 1) % 10)));
    }

    public function test_aceita_o_cnpj_alfanumerico_publicado_na_instrucao_normativa(): void
    {
        // '12.ABC.345-01DE-35' é o exemplo alfanumérico da RFB IN 2.119/2022. Ele só
        // fecha com o valor do caractere igual a `ord($char) - 48`: com a letra contada
        // como posição no alfabeto (A=10) o documento fecharia em 12ABC34501DE45.
        $this->assertTrue((new BrazilianTaxId)->isValidCnpj('12.ABC.345-01DE-35'));
    }

    public function test_cpf_continua_numerico_e_rejeita_letra(): void
    {
        $service = new BrazilianTaxId;

        $this->assertTrue($service->isValidCpf('52998224725'));
        $this->assertFalse($service->isValidCpf('529ABC24725'));
        // '10000003700' é um CPF válido; com os dois dígitos trocados por 'A', o
        // `(int) 'A'` do cálculo valeria zero e o documento passaria sem `ctype_digit`.
        $this->assertTrue($service->isValidCpf('10000003700'));
        $this->assertFalse($service->isValidCpf('100000037AA'));
    }
}
