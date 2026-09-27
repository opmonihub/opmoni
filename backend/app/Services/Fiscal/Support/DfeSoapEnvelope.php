<?php

namespace App\Services\Fiscal\Support;

final class DfeSoapEnvelope
{
    /**
     * O serviço de distribuição não usa cabeçalho SOAP e não assina a
     * requisição: a autenticação é o certificado no transporte. O
     * `SOAPAction` viaja no cabeçalho `Content-Type` da chamada, montado pelo
     * conector a partir da configuração — não no corpo, que é o que a
     * assinatura do schema proíbe.
     */
    public function build(
        string $serviceNamespace,
        string $payloadNamespace,
        string $version,
        string $cnpj,
        string $cUf,
        int $fromNsu,
        string $method,
    ): string {
        $cursor = str_pad((string) $fromNsu, 15, '0', STR_PAD_LEFT);

        $payload = '<distDFeInt xmlns="'.$payloadNamespace.'" versao="'.$version.'">'
            .'<tpAmb>'.(config('fiscal.environment') === 'producao' ? '1' : '2').'</tpAmb>'
            .'<cUFAutor>'.$cUf.'</cUFAutor>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .'<distNSU><ultNSU>'.$cursor.'</ultNSU></distNSU>'
            .'</distDFeInt>';

        $inner = '<'.$method.' xmlns="'.$serviceNamespace.'">'
            .'<nfeDadosMsg xmlns="'.$serviceNamespace.'">'.$payload.'</nfeDadosMsg>'
            .'</'.$method.'>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap:Body>'.$inner.'</soap:Body>'
            .'</soap:Envelope>';
    }
}
