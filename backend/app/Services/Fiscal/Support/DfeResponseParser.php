<?php

namespace App\Services\Fiscal\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class DfeResponseParser
{
    /**
     * Localiza o corpo por nome local para não depender de prefixo nem do
     * nome do elemento de resposta, que varia entre implementações.
     */
    public function parse(string $soapResponse): DfeResponse
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($soapResponse);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('A resposta do serviço não é um XML legível.');
        }

        $xpath = new DOMXPath($dom);
        $body = XmlQuery::first($xpath, 'retDistDFeInt');

        if ($body === null) {
            throw new RuntimeException('A resposta do serviço não contém retDistDFeInt.');
        }

        return new DfeResponse(
            cStat: $this->text($xpath, $body, 'cStat') ?? '',
            xMotivo: $this->text($xpath, $body, 'xMotivo') ?? '',
            ultNsu: (int) ($this->text($xpath, $body, 'ultNSU') ?? '0'),
            maxNsu: ($max = $this->text($xpath, $body, 'maxNSU')) === null ? null : (int) $max,
            entries: $this->entries($xpath, $body),
        );
    }

    /**
     * @return list<DfeEntry>
     */
    private function entries(DOMXPath $xpath, DOMElement $body): array
    {
        $entries = [];

        foreach (XmlQuery::all($xpath, 'docZip', $body) as $node) {
            $entries[] = new DfeEntry(
                nsu: (int) $node->getAttribute('NSU'),
                schema: $node->getAttribute('schema'),
                payload: $node->textContent,
            );
        }

        return $entries;
    }

    private function text(DOMXPath $xpath, DOMElement $scope, string $localName): ?string
    {
        return XmlQuery::first($xpath, $localName, $scope)?->textContent;
    }
}
