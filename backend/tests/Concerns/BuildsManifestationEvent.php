<?php

namespace Tests\Concerns;

use App\Services\Fiscal\Support\FiscalEnvironment;

/**
 * O evento de Ciência da Operação (210210) como o serviço `nfeRecepcaoEvento`
 * espera recebê-lo, montado à mão no teste. A montagem de produção vem numa
 * tarefa posterior; o que importa aqui é um `<evento>` com `infEvento` válido
 * para o XSD de manifestação (`envConfRecebto_v1.00`) e com o `Id` na regra do
 * leiaute: `ID` + tpEvento + chave de acesso + nSeqEvento com dois dígitos.
 */
trait BuildsManifestationEvent
{
    /** Chave sintética de 44 dígitos: UF 35, competência 26/10, CNPJ 00000000000191. */
    protected const CHAVE_DE_ACESSO = '35261000000000000191550010000000011123456780';

    protected const ID_DO_EVENTO = 'ID210210'.self::CHAVE_DE_ACESSO.'01';

    protected const NAMESPACE_NFE = 'http://www.portalfiscal.inf.br/nfe';

    protected const NAMESPACE_XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    protected function eventoDeCiencia(string $id = self::ID_DO_EVENTO, string $cnpj = '00000000000191'): string
    {
        return '<evento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<infEvento Id="'.$id.'">'
            .'<cOrgao>91</cOrgao>'
            .'<tpAmb>2</tpAmb>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .'<chNFe>'.self::CHAVE_DE_ACESSO.'</chNFe>'
            .'<dhEvento>2026-10-03T15:00:00-03:00</dhEvento>'
            .'<tpEvento>210210</tpEvento>'
            .'<nSeqEvento>1</nSeqEvento>'
            .'<verEvento>1.00</verEvento>'
            .'<detEvento versao="1.00">'
            .'<descEvento>Ciencia da Operacao</descEvento>'
            .'</detEvento>'
            .'</infEvento>'
            .'</evento>';
    }

    /**
     * O lote de envio que embrulha o evento (assinado ou não) para o serviço.
     */
    protected function envEventoCom(string $evento): string
    {
        return '<envEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote>'
            .$evento
            .'</envEvento>';
    }

    /**
     * O `retEnvEvento` do serviço, com `infEvento` correlacionado ao pedido padrão.
     */
    protected function retEnvEvento(
        string $cStatLote,
        string $xMotivoLote,
        ?string $cStatEvento = null,
        ?string $xMotivoEvento = null,
        ?string $chave = self::CHAVE_DE_ACESSO,
        string $tpEvento = '210210',
        string $nSeqEvento = '1',
        ?string $tpAmb = null,
    ): string {
        $tpAmb ??= FiscalEnvironment::tpAmb();
        $retEvento = $cStatEvento === null ? '' : '<retEvento versao="1.00">'
            .'<infEvento>'
            ."<tpAmb>{$tpAmb}</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>"
            ."<chNFe>{$chave}</chNFe><tpEvento>{$tpEvento}</tpEvento><nSeqEvento>{$nSeqEvento}</nSeqEvento>"
            ."<cStat>{$cStatEvento}</cStat><xMotivo>{$xMotivoEvento}</xMotivo>"
            .'<dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/RecepcaoEvento">'
            .'<nfeRecepcaoEventoResult>'
            .'<retEnvEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote><tpAmb>'.$tpAmb.'</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>{$xMotivoLote}</xMotivo>"
            .$retEvento
            .'</retEnvEvento></nfeRecepcaoEventoResult>'
            .'</nfeRecepcaoEventoResponse></soap:Body></soap:Envelope>';
    }
}
