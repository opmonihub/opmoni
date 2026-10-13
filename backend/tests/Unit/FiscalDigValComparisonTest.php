<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DigValComparison;
use PHPUnit\Framework\TestCase;

/**
 * A comparação de `digVal` é função pura de duas strings, então os testes são
 * sintéticos de propósito: um par igual e um par divergente, sem rede e sem
 * documento.
 *
 * > Os fixtures do módulo são sintéticos, estruturalmente realistas, não
 * > capturas do ambiente nacional. Um par `resNFe` + `procNFe` capturado de
 * > verdade, e um par deliberadamente divergente, têm de vir do serviço real
 * > antes de produção.
 */
class FiscalDigValComparisonTest extends TestCase
{
    /**
     * SHA-1 de 20 bytes em base64, que é sempre 28 caracteres — o que a coluna
     * `digval` aceita. O valor de 32 caracteres que estava aqui antes era mais
     * longo que qualquer digest real, e o `varchar(28)` do Postgres recusaria
     * escrevê-lo.
     */
    private const DIGVAL = 'i2rqNaD6rqmCfhXHyTBf4xe1ImQ=';

    public function test_two_equal_digests_confirm_each_other(): void
    {
        $this->assertTrue(DigValComparison::compare(self::DIGVAL, self::DIGVAL));
    }

    public function test_two_different_digests_are_a_divergence(): void
    {
        $outro = base64_encode(str_repeat("\xF5", 20));

        $this->assertNotSame(self::DIGVAL, $outro);
        $this->assertFalse(DigValComparison::compare(self::DIGVAL, $outro));
        $this->assertFalse(DigValComparison::compare($outro, self::DIGVAL));
    }

    public function test_the_comparison_is_case_sensitive(): void
    {
        // Base64 maiúsculo e minúsculo não são o mesmo valor: afrouxar aqui
        // transformaria um digest real em divergência sem motivo, ou pior, o
        // contrário.
        $this->assertFalse(DigValComparison::compare(self::DIGVAL, strtolower(self::DIGVAL)));
    }

    public function test_a_missing_side_is_not_a_divergence(): void
    {
        // Um lado ausente é "não dá para dizer", e não "não confere": documento
        // de evento não tem `digVal`, e uma captura que começa no meio da fila
        // nunca vê o resumo com que comparar. Ler ausência como divergência
        // marcaria de corrompido todo documento de etapa única.
        $this->assertNull(DigValComparison::compare(self::DIGVAL, null));
        $this->assertNull(DigValComparison::compare(null, self::DIGVAL));
        $this->assertNull(DigValComparison::compare(null, null));
    }

    public function test_an_empty_digest_is_treated_as_a_missing_one(): void
    {
        // Uma coluna que volte vazia em vez de nula não pode virar um veredito
        // negativo: string vazia é a ausência de digest, não um digest.
        $this->assertNull(DigValComparison::compare(self::DIGVAL, ''));
        $this->assertNull(DigValComparison::compare('', self::DIGVAL));
    }
}
