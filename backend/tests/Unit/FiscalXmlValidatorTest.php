<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\Fiscal\Support\XmlQuery;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Tests\TestCase;

/**
 * O schema local que barra a requisição antes de qualquer byte na rede.
 *
 * O serviço e a versão são parâmetros: cada serviço de distribuição tem a sua
 * pasta em `resources/xsd/` e a sua versão de leiaute, e a versão que valida o
 * corpo é a mesma que vai no atributo `versao` dele. Por isso o mesmo payload
 * de CT-e é recusado pelo XSD de NF-e, e o teste abaixo é o que prova que a
 * pasta é o que decide.
 */
class FiscalXmlValidatorTest extends TestCase
{
    public function test_accepts_the_request_the_envelope_builds(): void
    {
        $this->validate($this->payloadDaConsulta());

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

        $this->validate($request);
    }

    public function test_rejects_a_request_with_a_prefixed_namespace(): void
    {
        $request = str_replace(
            ['<distDFeInt ', '</distDFeInt>'],
            ['<nfe:distDFeInt xmlns:nfe="http://www.portalfiscal.inf.br/nfe" ', '</nfe:distDFeInt>'],
            $this->payloadDaConsulta(),
        );

        $this->expectException(RuntimeException::class);

        $this->validate($request);
    }

    public function test_rejects_a_request_with_non_utf8_encoding_declaration(): void
    {
        $request = '<?xml version="1.0" encoding="ISO-8859-1"?>'.$this->payloadDaConsulta();

        $this->expectException(RuntimeException::class);

        $this->validate($request);
    }

    public function test_rejects_an_unsupported_version(): void
    {
        // O XSD do serviço declara a versão que o serviço publica, e a
        // enumeração é fechada: um corpo de outra versão é recusado localmente.
        // `1.00` é a versão do CT-e, e por isso ela é recusada **pelo XSD da
        // NF-e** — os dois serviços não dividem a pasta.
        $this->expectException(RuntimeException::class);

        $this->validate($this->payloadDaConsulta(version: '1.00'));
    }

    public function test_rejects_a_cursor_that_is_not_fifteen_digits(): void
    {
        $request = str_replace(
            '<ultNSU>000000000000042</ultNSU>',
            '<ultNSU>42</ultNSU>',
            $this->payloadDaConsulta(fromNsu: 42),
        );

        $this->expectException(RuntimeException::class);

        $this->validate($request);
    }

    public function test_rejects_malformed_xml(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validate('<distDFeInt><tpAmb>1</distDFeInt>');
    }

    public function test_rejects_an_unknown_schema(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validate($this->payloadDaConsulta(), schema: 'distDFeIntQueNaoExiste');
    }

    public function test_accepts_the_cte_request_the_envelope_builds(): void
    {
        // O mesmo envelope, montado com os parâmetros do CT-e: o XSD do CT-e é
        // o que valida o payload dele, e é a pasta do serviço que decide.
        $this->validate($this->payloadDaConsulta(fonte: 'cte_distribuicao'), service: 'cte', version: '1.00');

        $this->assertTrue(true);
    }

    public function test_rejects_the_cte_request_on_the_nfe_schema(): void
    {
        // Namespace de payload e versão são do serviço, e o XSD da NF-e declara
        // os dele: um corpo de CT-e num schema de NF-e é recusado por namespace,
        // antes de qualquer byte. É o que impede a validação de passar em branco
        // para o serviço errado.
        $this->expectException(RuntimeException::class);

        $this->validate($this->payloadDaConsulta(fonte: 'cte_distribuicao'));
    }

    /**
     * O XSD descreve o `distDFeInt`, não o envelope SOAP que o embrulha: o
     * conector entrega o payload, e o fixture vem do envelope de verdade para
     * que os dois não divirjam sobre a forma do documento.
     */
    private function payloadDaConsulta(
        string $fonte = 'nfe_distribuicao',
        ?string $version = null,
        int $fromNsu = 0,
    ): string {
        $endpoint = config("fiscal.endpoints.{$fonte}");

        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $version ?? $endpoint['version'],
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

    private function validate(
        string $xml,
        string $schema = 'distDFeInt',
        string $service = 'nfe',
        string $version = '1.01',
    ): void {
        (new FiscalXmlValidator)->validate($xml, $schema, $service, $version);
    }
}
