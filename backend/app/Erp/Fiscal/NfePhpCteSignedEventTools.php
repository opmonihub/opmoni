<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use NFePHP\CTe\Tools;
use RuntimeException;

final class NfePhpCteSignedEventTools extends Tools
{
    public function submitSignedEventXml(string $signedXml, string $uf): string
    {
        $dom = new DOMDocument;
        if ($signedXml === '' || ! $dom->loadXML($signedXml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement
            || $dom->documentElement->localName !== 'eventoCTe'
            || $dom->documentElement->namespaceURI !== 'http://www.portalfiscal.inf.br/cte') {
            throw new RuntimeException('Evento CT-e assinado inválido.');
        }
        $this->checkContingencyForWebServices('CteRecepcaoEvento');
        $this->servico('CteRecepcaoEvento', $uf, $this->tpAmb);
        $this->isValid($this->urlVersion, $signedXml, 'eventoCTe');
        $this->lastRequest = $signedXml;
        $this->objHeader = null;
        $request = '<cteDadosMsg xmlns="'.$this->urlNamespace.'">'.$signedXml.'</cteDadosMsg>';
        $this->lastResponse = $this->sendRequest($request, ['cteDadosMsg' => $signedXml]);

        return $this->lastResponse;
    }
}
