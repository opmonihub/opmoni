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

    /**
     * A recusa de XSD ausente é nomeada por serviço, schema, versão e caminho
     * resolvido — e a mensagem é o que se fixa, não só o tipo da exceção.
     *
     * O caminho já carrega serviço e versão, então repetir os dois na frase é
     * repetir o par que decide **qual** arquivo era o esperado: um
     * `xsd_service` trocado gera um caminho que não existe, e sem os
     * discriminadores a recusa seria "não encontrado" e o operador teria de
     * adivinhar de qual dos dois lados veio o erro. O caminho resolvido fecha a
     * pista porque é ele que a pasta de `resources/xsd/` deveria ter.
     */
    public function test_rejects_an_unknown_schema(): void
    {
        try {
            $this->validate($this->payloadDaConsulta(), schema: 'distDFeIntQueNaoExiste');

            $this->fail('Um schema que não existe precisa ser recusado.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'XSD não encontrado: nfe distDFeIntQueNaoExiste v1.01 ('.resource_path('xsd/nfe/distDFeIntQueNaoExiste_v1.01.xsd').').',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A mesma recusa no serviço de CT-e, e com a versão do CT-e: os três
     * discriminadores aparecem, e um bloco de endpoint com a versão trocada pelo
     * serviço de NF-e é recusado aqui antes de qualquer byte, e não depois.
     */
    public function test_a_recusa_de_xsd_ausente_diz_o_servico_e_a_versao(): void
    {
        try {
            $this->validate($this->payloadDaConsulta(), service: 'cte', version: '9.99');

            $this->fail('Uma versão que não existe precisa ser recusada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cte distDFeInt v9.99', $exception->getMessage());
            $this->assertStringContainsString('xsd/cte/distDFeInt_v9.99.xsd', $exception->getMessage());
        }
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
     * A mesma assinatura injetada, agora no schema do **CT-e**, e a mesma
     * recusa local.
     *
     * A meia verdade do lado de NF-e é que o `cStat 215` do serviço é um fato
     * verificado do serviço de NF-e, e o XSD local é a outra metade: um
     * `xs:sequence` fechado, sem `xs:any` algum, que não abre espaço para
     * elemento nenhum além do que ele lista. Do CT-e **ninguém sabe** — este
     * repositório não falou com o serviço uma vez sequer —, e o que se afirma
     * aqui é só a parte local: o mesmo payload sem a assinatura passa
     * (`test_accepts_the_cte_request_the_envelope_builds`), e são os dois testes
     * juntos que provam que foi a sequência fechada que recusou. Um `215` vindo
     * do serviço de CT-e é hipótese do canário, e está escrito como tal em
     * `config/fiscal.php`.
     */
    public function test_rejects_the_cte_request_with_an_injected_signature(): void
    {
        $request = str_replace(
            '</distDFeInt>',
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></distDFeInt>',
            $this->payloadDaConsulta(fonte: 'cte_distribuicao'),
        );

        $this->expectException(RuntimeException::class);

        $this->validate($request, service: 'cte', version: '1.00');
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
