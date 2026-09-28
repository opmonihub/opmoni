<?php

namespace App\Services\Fiscal\Support;

use DOMDocument;
use RuntimeException;

final class FiscalXmlValidator
{
    /**
     * Valida a requisição contra o XSD local antes de enviar. A validação
     * remove a classe inteira de rejeições de forma (schema, versão, cursor,
     * assinatura injetada) sem ida à rede.
     *
     * O serviço e a versão moram no nome do arquivo
     * (`nfe/distDFeInt_v1.01.xsd`, `cte/distDFeInt_v1.00.xsd`): uma versão por
     * diretório de serviço, junto dos `xs:include` que ela puxa. A versão chega
     * como argumento e não é escrita aqui de propósito — é a mesma que vai no
     * atributo `versao` do corpo, e as duas não podem divergir. Um XSD de outra
     * versão aceitaria um corpo que o serviço rejeitaria, que é a rejeição mais
     * cara de evitar.
     */
    public function validate(string $xml, string $schemaName, string $service, string $version): void
    {
        $schema = resource_path("xsd/{$service}/{$schemaName}_v{$version}.xsd");

        if (! is_file($schema)) {
            throw new RuntimeException("XSD não encontrado: {$schemaName}.");
        }

        self::rejectUnsupportedEncodingDeclaration($xml);

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        // O libxml acumula erro em buffer global; pegamos o que ele produzir
        // aqui e devolvemos o estado anterior, senão a próxima validação lê
        // erro de quem rodou antes.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $dom->loadXML($xml)) {
                throw new RuntimeException('XML malformado: '.self::primeiroErro());
            }

            self::rejectPrefixedElements($dom);

            if (! $dom->schemaValidate($schema)) {
                throw new RuntimeException('Requisição rejeitada pelo schema: '.self::primeiroErro());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * A primeira mensagem do libxml é a que o log mostra: o resto do buffer
     * descreve consequência, não causa. Quando o buffer vem vazio — o que
     * acontece quando a validação falha sem produzir erro, o que o libxml
     * permite — a exceção ainda precisa dizer alguma coisa.
     */
    private static function primeiroErro(): string
    {
        return trim(libxml_get_errors()[0]->message ?? 'erro desconhecido');
    }

    private static function rejectUnsupportedEncodingDeclaration(string $xml): void
    {
        if (! preg_match('/^\s*<\?xml\s+[^?]*encoding=["\']([^"\']+)["\']/i', $xml, $matches)) {
            return;
        }

        if (strcasecmp($matches[1], 'UTF-8') !== 0) {
            throw new RuntimeException('XML com codificação incompatível: '.$matches[1].'.');
        }
    }

    private static function rejectPrefixedElements(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->prefix !== '' && $element->prefix !== null) {
                throw new RuntimeException('XML com prefixo de namespace incompatível: '.$element->prefix.'.');
            }
        }
    }
}
