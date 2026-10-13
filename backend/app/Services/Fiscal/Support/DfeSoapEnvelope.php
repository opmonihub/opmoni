<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;

final class DfeSoapEnvelope
{
    /**
     * O serviço de distribuição não usa cabeçalho SOAP e não assina a
     * requisição: a autenticação é o certificado no transporte. O
     * `SOAPAction` viaja no cabeçalho `Content-Type` da chamada, montado pelo
     * conector a partir da configuração — não no corpo, que é o que a
     * assinatura do schema proíbe.
     *
     * `holder` é o elemento que embrulha o payload (`nfeDadosMsg` na NF-e,
     * `cteDadosMsg` no CT-e) e vem da configuração pelo mesmo caminho das
     * demais diferenças entre os serviços, para que nenhum conector precise
     * editar esta classe.
     */
    public function build(
        string $serviceNamespace,
        string $payloadNamespace,
        string $version,
        string $cnpj,
        string $cUf,
        int $fromNsu,
        string $method,
        string $holder,
    ): string {
        $cursor = str_pad((string) $fromNsu, 15, '0', STR_PAD_LEFT);

        $payload = '<distDFeInt xmlns="'.$payloadNamespace.'" versao="'.$version.'">'
            .'<tpAmb>'.(config('fiscal.environment') === 'producao' ? '1' : '2').'</tpAmb>'
            .'<cUFAutor>'.$cUf.'</cUFAutor>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .'<distNSU><ultNSU>'.$cursor.'</ultNSU></distNSU>'
            .'</distDFeInt>';

        $inner = '<'.$method.' xmlns="'.$serviceNamespace.'">'
            .'<'.$holder.' xmlns="'.$serviceNamespace.'">'.$payload.'</'.$holder.'>'
            .'</'.$method.'>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap:Body>'.$inner.'</soap:Body>'
            .'</soap:Envelope>';
    }

    /**
     * Converte um corpo de consulta por posição em consulta por posição
     * específica: é o grupo de consulta que muda, o resto do envelope não.
     *
     * O `build()` monta o corpo com o grupo incremental, e a troca é a
     * diferença entre "me diga tudo a partir de X" e "me diga a posição X" — a
     * segunda é a consulta que fecha buraco, e o `distNSU` que sobrasse
     * devolveria um lote inteiro com aparência de resposta certa.
     *
     * Por isso a troca é contada: `str_replace` que não encontra o padrão
     * devolve o corpo intacto em silêncio, e um corpo sem grupo de posição — ou
     * com mais de um — é recusado em vez de reescrito no escuro.
     */
    public function pointNsu(string $envelope, int $nsu): string
    {
        $position = '<distNSU><ultNSU>'.str_pad('0', 15, '0', STR_PAD_LEFT).'</ultNSU></distNSU>';
        $point = '<consNSU><NSU>'.str_pad((string) $nsu, 15, '0', STR_PAD_LEFT).'</NSU></consNSU>';

        if (substr_count($envelope, $position) !== 1) {
            throw new RuntimeException('O envelope não pôde ser convertido em consulta por posição.');
        }

        return str_replace($position, $point, $envelope);
    }
}
