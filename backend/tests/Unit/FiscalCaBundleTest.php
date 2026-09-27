<?php

namespace Tests\Unit;

use Tests\TestCase;

class FiscalCaBundleTest extends TestCase
{
    public function test_the_configured_path_is_a_real_file(): void
    {
        $this->assertFileExists(config('fiscal.ca_bundle'));
    }

    public function test_the_bundle_is_pem_encoded(): void
    {
        $contents = file_get_contents(config('fiscal.ca_bundle'));

        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $contents);
        $this->assertStringContainsString('-----END CERTIFICATE-----', $contents);
    }

    public function test_the_bundle_holds_the_icp_brasil_anchor(): void
    {
        // Um bundle que não é a cadeia da ICP-Brasil não serve para nada: é o
        // que o `CURLOPT_CAINFO` do conector vai receber no lugar do trust
        // store, e o que o schema não cobre é justamente a autoridade do
        // servidor do fisco.
        $anchor = openssl_x509_parse(openssl_x509_read(file_get_contents(config('fiscal.ca_bundle'))));

        $this->assertSame('ICP-Brasil', $anchor['subject']['O']);
    }

    public function test_the_bundle_carries_more_than_one_anchor(): void
    {
        $contents = file_get_contents(config('fiscal.ca_bundle'));

        $this->assertGreaterThan(1, substr_count($contents, '-----BEGIN CERTIFICATE-----'));
    }
}
