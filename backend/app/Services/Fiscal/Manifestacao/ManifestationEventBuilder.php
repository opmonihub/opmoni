<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationEventType;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Support\FiscalEnvironment;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Carbon\CarbonInterface;

/**
 * O `<evento>` de manifestação e o lote `envEvento` que o embrulha, montados
 * a partir do que o resumo capturado já sabe: a chave, o autor (o `tax_id` do
 * cliente) e o momento.
 *
 * A regra do `Id` é do leiaute e o `EventSigner` não a inventa: `ID` +
 * tpEvento (6) + chave de acesso (44) + nSeqEvento com dois dígitos — 52
 * dígitos no total, que é o padrão `ID[0-9]{52}` que o XSD exige. Sequência
 * acima de 99 não cabe no leiaute e morre aqui, não no fisco.
 *
 * Duas recusas são `FiscalRequestNotSent` porque o evento não chega a existir
 * para o fisco: um resumo cuja chave não fecha o módulo 11 (a mesma guarda que
 * a captura aplica ao que entra) e um cliente sem CNPJ/CPF — sem autor não há
 * quem assine o ato, e o fisco não tem veredito a dar sobre isso.
 */
final class ManifestationEventBuilder
{
    private const NAMESPACE_NFE = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * O `descEvento` por tipo, do leiaute: a descrição que vai no `detEvento`
     * é literal do XSD, com a grafia que o serviço espera — e a mesma constante
     * alimenta o `Id` e o `tpEvento`, para os três não divergirem.
     */
    private const DESCRICOES = [
        '210210' => 'Ciencia da Operacao',
    ];

    /**
     * O `cOrgao` do evento é sempre o do Ambiente Nacional: é ele, e não a UF
     * do emitente, que registra a manifestação do destinatário.
     */
    private const ORGAO_AN = '91';

    public function evento(
        string $chaveAcesso,
        ?string $autor,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
        ?CarbonInterface $agora = null,
    ): string {
        if (! FiscalXmlMetadata::isValidChave($chaveAcesso)) {
            throw new FiscalRequestNotSent('Chave de acesso inválida para montar o evento de manifestação.');
        }

        $documento = $this->documentoDoAutor($autor);

        if ($documento === null) {
            throw new FiscalRequestNotSent('Cliente sem CNPJ ou CPF para assinar a manifestação.');
        }

        if ($eventSeq < 1 || $eventSeq > 99) {
            throw new FiscalRequestNotSent('Sequência de evento fora do leiaute da manifestação.');
        }

        $descEvento = self::DESCRICOES[$eventType->value] ?? null;

        if ($descEvento === null) {
            throw new FiscalRequestNotSent('Tipo de evento sem descrição de leiaute.');
        }

        $agora ??= now();
        $id = 'ID'.$eventType->value.$chaveAcesso.str_pad((string) $eventSeq, 2, '0', STR_PAD_LEFT);
        // O `tpAmb` é fail-closed como a URL do transporte: um ambiente fora
        // da lista recusa a montagem em vez de mandar o evento assinado para
        // a homologação por engano.
        $tpAmb = FiscalEnvironment::tpAmb();
        // O fisco espera o horário do fuso do Brasil (-03:00), não o do
        // servidor: o `dhEvento` é escrito no fuso de São Paulo mesmo quando o
        // processo roda em UTC.
        $dhEvento = $agora->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:sP');

        return '<evento xmlns="'.self::NAMESPACE_NFE.'" versao="'.EventEnvelopeValidator::VERSION.'">'
            .'<infEvento Id="'.$id.'">'
            .'<cOrgao>'.self::ORGAO_AN.'</cOrgao>'
            .'<tpAmb>'.$tpAmb.'</tpAmb>'
            .'<'.$documento['tag'].'>'.$documento['valor'].'</'.$documento['tag'].'>'
            .'<chNFe>'.$chaveAcesso.'</chNFe>'
            .'<dhEvento>'.$dhEvento.'</dhEvento>'
            .'<tpEvento>'.$eventType->value.'</tpEvento>'
            .'<nSeqEvento>'.$eventSeq.'</nSeqEvento>'
            .'<verEvento>'.EventEnvelopeValidator::VERSION.'</verEvento>'
            .'<detEvento versao="'.EventEnvelopeValidator::VERSION.'">'
            .'<descEvento>'.$descEvento.'</descEvento>'
            .'</detEvento>'
            .'</infEvento>'
            .'</evento>';
    }

    /**
     * O lote que viaja no corpo SOAP. O `idLote` é informativo para o serviço e
     * volta no `retEnvEvento`; a versão é a mesma constante do validador, para
     * quem monta e quem confere nunca divergirem.
     */
    public function envEvento(string $eventoAssinado): string
    {
        return '<envEvento xmlns="'.self::NAMESPACE_NFE.'" versao="'.EventEnvelopeValidator::VERSION.'">'
            .'<idLote>1</idLote>'
            .$eventoAssinado
            .'</envEvento>';
    }

    /**
     * O autor do evento é o `tax_id` do cliente: 14 dígitos é CNPJ, 11 é CPF —
     * o XSD aceita os dois pelo `choice` do `infEvento`. Qualquer outra forma
     * (vazio, máscara, tamanho errado) não é autor utilizável.
     *
     * @return array{tag: string, valor: string}|null
     */
    private function documentoDoAutor(?string $autor): ?array
    {
        $digitos = preg_replace('/\D/', '', (string) $autor);

        return match (strlen($digitos)) {
            14 => ['tag' => 'CNPJ', 'valor' => $digitos],
            11 => ['tag' => 'CPF', 'valor' => $digitos],
            default => null,
        };
    }
}
