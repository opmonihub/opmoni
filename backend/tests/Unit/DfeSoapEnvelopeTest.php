<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeSoapEnvelope;
use Tests\TestCase;

class DfeSoapEnvelopeTest extends TestCase
{
    public function test_builds_soap_12_envelope_without_header(): void
    {
        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe',
            payloadNamespace: 'http://www.portalfiscal.inf.br/nfe',
            version: '1.01',
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: 0,
            method: 'nfeDistDFeInteresse',
            holder: 'nfeDadosMsg',
        );

        $this->assertStringContainsString('http://www.w3.org/2003/05/soap-envelope', $envelope);
        $this->assertStringContainsString('<nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">', $envelope);
        $this->assertStringContainsString('<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">', $envelope);
        $this->assertStringContainsString('<cUFAutor>35</cUFAutor>', $envelope);
        $this->assertStringContainsString('<CNPJ>00000000000191</CNPJ>', $envelope);
        $this->assertStringContainsString('<ultNSU>000000000000000</ultNSU>', $envelope);
        $this->assertStringNotContainsString('<soap:Header', $envelope);
        $this->assertStringNotContainsString('Signature', $envelope);
    }

    public function test_pads_the_cursor_to_fifteen_digits(): void
    {
        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: 'ns',
            payloadNamespace: 'ns2',
            version: '1.01',
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: 42,
            method: 'nfeDistDFeInteresse',
            holder: 'nfeDadosMsg',
        );

        $this->assertStringContainsString('<ultNSU>000000000000042</ultNSU>', $envelope);
    }

    public function test_takes_the_holder_element_from_the_caller(): void
    {
        // O CT-e embrulha o payload em `dfeDadosMsg`. Um literal `nfeDadosMsg`
        // aqui obrigaria o conector de CT-e a editar a classe que o da NF-e
        // consome.
        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: 'ns',
            payloadNamespace: 'ns2',
            version: '1.00',
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: 0,
            method: 'cteDistDFeInteresse',
            holder: 'dfeDadosMsg',
        );

        $this->assertStringContainsString('<dfeDadosMsg xmlns="ns"><distDFeInt', $envelope);
        $this->assertStringContainsString('</dfeDadosMsg></cteDistDFeInteresse>', $envelope);
        $this->assertStringNotContainsString('nfeDadosMsg', $envelope);
    }
}
