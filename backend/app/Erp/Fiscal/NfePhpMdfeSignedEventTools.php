<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use NFePHP\MDFe\Tools;
use RuntimeException;

final class NfePhpMdfeSignedEventTools extends Tools
{
    public function submitSignedEventXml(string $signedXml, string $uf): string
    {
        $dom = new DOMDocument;
        if ($signedXml === '' || ! $dom->loadXML($signedXml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement
            || $dom->documentElement->localName !== 'eventoMDFe'
            || $dom->documentElement->namespaceURI !== 'http://www.portalfiscal.inf.br/mdfe') {
            throw new RuntimeException('Evento MDF-e assinado inválido.');
        }
        $this->servico('MDFeRecepcaoEvento', $uf, $this->tpAmb);
        $eventCode = $dom->getElementsByTagName('tpEvento')->item(0)?->textContent;
        $eventType = match ($eventCode) {
            '110111' => 'cancel', '110112' => 'close',
            default => throw new RuntimeException('Tipo de evento MDF-e não suportado.'),
        };
        app(ErpFiscalSchemaValidator::class)->assertValid($signedXml, '58', 'event', $eventType);
        $this->lastRequest = $signedXml;
        $parameters = ['mdfeDadosMsg' => $signedXml];
        $request = '<mdfeDadosMsg xmlns="'.$this->urlNamespace.'">'.$signedXml.'</mdfeDadosMsg>';
        $this->lastResponse = $this->sendRequest($request, $parameters);

        return $this->lastResponse;
    }
}
