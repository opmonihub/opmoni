<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Services\Fiscal\Support\FiscalXmlValidator;

/**
 * O lote de manifestação (`envEvento`) é conferido contra o XSD local do
 * serviço de eventos antes de qualquer byte na rede — a mesma disciplina que o
 * `DfeTransport` aplica ao `distDFeInt`, com o schema que o AN usa para o
 * `nfeRecepcaoEvento` da Manifestação do Destinatário.
 *
 * O schema é o `envConfRecebto_v1.00` do pacote PL_009 (ver cabeçalho do
 * arquivo em `resources/xsd/nfe/`), e ele puxa `leiauteConfRecebto`,
 * `tiposBasico_v1.03` e `xmldsig-core-schema_v1.01` pelo `schemaLocation`
 * relativo. É um schema fechado que **exige** o `Signature`: um evento que
 * escapou do `EventSigner` é recusado aqui, não pelo fisco. E como o
 * `FiscalXmlValidator` recusa elemento prefixado, o `Signature` precisa estar
 * em namespace default — que é exatamente como o assinador o produz.
 *
 * A versão é fixa porque o serviço publica uma só (`TVerEnvEvento` casa com
 * `1\.00`), e ela é a mesma que vai no atributo `versao` do lote. Quem monta o
 * envelope e quem valida não podem divergir sobre isso.
 *
 * O XML não sai daqui para log nenhum: a exceção do validador carrega só a
 * primeira mensagem do libxml, que é a causa e não o documento.
 */
final class EventEnvelopeValidator
{
    public const SCHEMA = 'envConfRecebto';

    public const SERVICE = 'nfe';

    public const VERSION = '1.00';

    public function __construct(private FiscalXmlValidator $validator) {}

    /**
     * @throws \RuntimeException quando o lote não passa no schema local
     */
    public function validate(string $envEventoXml): void
    {
        $this->validator->validate($envEventoXml, self::SCHEMA, self::SERVICE, self::VERSION);
    }
}
