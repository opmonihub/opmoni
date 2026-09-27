<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\Fiscal\Support\XmlQuery;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Tests\TestCase;

class FiscalXmlValidatorTest extends TestCase
{
    public function test_accepts_the_request_the_envelope_builds(): void
    {
        (new FiscalXmlValidator)->validate($this->payloadDaConsulta(), 'distDFeInt');

        $this->assertTrue(true);
    }

    public function test_rejects_a_request_with_an_injected_signature(): void
    {
        // A assinatura injetada é rejeitada com `cStat 215` pelo serviço, e o
        // XSD local diz o mesmo antes: o schema não declara `Signature` e não
        // abre espaço para elemento nenhum além da sequência que ele lista.
        $request = str_replace(
            '</distDFeInt>',
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></distDFeInt>',
            $this->payloadDaConsulta(),
        );

        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($request, 'distDFeInt');
    }

    public function test_rejects_an_unsupported_version(): void
    {
        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($this->payloadDaConsulta(version: '1.00'), 'distDFeInt');
    }

    public function test_rejects_a_cursor_that_is_not_fifteen_digits(): void
    {
        $request = str_replace(
            '<ultNSU>000000000000042</ultNSU>',
            '<ultNSU>42</ultNSU>',
            $this->payloadDaConsulta(fromNsu: 42),
        );

        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($request, 'distDFeInt');
    }

    public function test_rejects_malformed_xml(): void
    {
        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate('<distDFeInt><tpAmb>1</distDFeInt>', 'distDFeInt');
    }

    public function test_rejects_an_unknown_schema(): void
    {
        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($this->payloadDaConsulta(), 'distDFeIntQueNaoExiste');
    }

    /**
     * O XSD descreve o `distDFeInt`, não o envelope SOAP que o embrulha: o
     * conector entrega o payload, e o fixture vem do envelope de verdade para
     * que os dois não divirjam sobre a forma do documento.
     */
    private function payloadDaConsulta(string $version = '1.01', int $fromNsu = 0): string
    {
        $endpoint = config('fiscal.endpoints.nfe_distribuicao');

        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $version,
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: $fromNsu,
            method: $endpoint['method'],
            holder: $endpoint['holder'],
        );

        $dom = new DOMDocument;
        $dom->loadXML($envelope);

        return $dom->saveXML(XmlQuery::first(new DOMXPath($dom), 'distDFeInt'));
    }
}
