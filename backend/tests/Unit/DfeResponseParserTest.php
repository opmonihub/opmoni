<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DocZipDecoder;
use RuntimeException;
use Tests\TestCase;

class DfeResponseParserTest extends TestCase
{
    public function test_parses_response_with_entries(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_138.xml'));

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('138', $response->cStat);
        $this->assertSame(200, $response->ultNsu);
        $this->assertSame(200, $response->maxNsu);
        $this->assertCount(1, $response->entries);
        $this->assertSame(200, $response->entries[0]->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $response->entries[0]->schema);
        $this->assertNotSame('', $response->entries[0]->payload);
    }

    public function test_is_immune_to_namespace_prefix(): void
    {
        $xml = <<<'XML'
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
          <soap:Body>
            <nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
              <nfeDistDFeInteresseResult>
                <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
                  <cStat>137</cStat>
                  <xMotivo>Nenhum documento localizado</xMotivo>
                  <ultNSU>000000000000000</ultNSU>
                  <maxNSU>000000000000000</maxNSU>
                </retDistDFeInt>
              </nfeDistDFeInteresseResult>
            </nfeDistDFeInteresseResponse>
          </soap:Body>
        </soap:Envelope>
        XML;

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('137', $response->cStat);
        $this->assertSame(0, $response->ultNsu);
        $this->assertSame([], $response->entries);
    }

    public function test_entry_payload_decodes_to_the_document_it_wraps(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_138.xml'));

        $payload = (new DfeResponseParser)->parse($xml)->entries[0]->payload;

        // O serviço devolve a base64 do lote com quebras de linha; a
        // higienização é o que impede a decomposição de falhar em produção.
        $this->assertStringContainsString("\n", $payload);

        $this->assertStringContainsString(
            '<chNFe>35220499999999999999550010020000001240556600</chNFe>',
            (new DocZipDecoder)->decode($payload),
        );
    }

    public function test_rejection_carries_the_cursor_inside_the_body(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_656_com_nsu.xml'));

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('656', $response->cStat);
        $this->assertSame(1678, $response->ultNsu);
    }

    public function test_throws_when_body_has_no_ret_dist_dfe_int(): void
    {
        $this->expectException(RuntimeException::class);

        (new DfeResponseParser)->parse('<html><body>502 Bad Gateway</body></html>');
    }
}
