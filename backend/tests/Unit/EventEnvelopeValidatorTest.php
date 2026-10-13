<?php

namespace Tests\Unit;

use App\Services\Fiscal\Manifestacao\EventEnvelopeValidator;
use App\Services\Fiscal\Manifestacao\EventSigner;
use RuntimeException;
use Tests\Concerns\BuildsManifestationEvent;
use Tests\Concerns\BuildsThrowawayPkcs12;
use Tests\TestCase;

/**
 * O XSD local do serviço de eventos barra o lote de manifestação antes de
 * qualquer byte na rede — a mesma disciplina do `distDFeInt`, agora para o
 * `envEvento` do pacote de Manifestação do Destinatário (`envConfRecebto_v1.00`,
 * PL_009).
 *
 * O schema é fechado e exige o `Signature`: um lote sem assinatura, com
 * assinatura prefixada, com `descEvento` fora da enumeração ou com `Id` fora
 * da regra é recusado localmente. O envelope aceito é o que o `EventSigner`
 * produz, para a montagem, a assinatura e o schema não divergirem.
 */
class EventEnvelopeValidatorTest extends TestCase
{
    use BuildsManifestationEvent;
    use BuildsThrowawayPkcs12;

    /** @var array{bytes: string, password: string, cert: string}|null */
    private static ?array $a1 = null;

    private static ?string $eventoAssinado = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$a1 = self::throwawayPkcs12();
    }

    public static function tearDownAfterClass(): void
    {
        self::$a1 = null;
        self::$eventoAssinado = null;
        self::cleanupThrowawayPkcs12();

        parent::tearDownAfterClass();
    }

    public function test_aceita_o_lote_com_o_evento_que_o_assinador_produz(): void
    {
        $this->validator()->validate($this->envEventoCom($this->eventoAssinado()));

        $this->assertTrue(true);
    }

    public function test_rejeita_o_lote_com_evento_sem_assinatura(): void
    {
        // O XSD do evento declara `ds:Signature` obrigatório: um evento que
        // escapou do assinador não chega ao transporte.
        $this->expectException(RuntimeException::class);

        $this->validator()->validate($this->envEventoCom($this->eventoDeCiencia()));
    }

    public function test_rejeita_assinatura_com_prefixo_ds(): void
    {
        $prefixado = str_replace(
            ['<Signature xmlns="'.self::NAMESPACE_XMLDSIG.'">', '</Signature>'],
            ['<ds:Signature xmlns:ds="'.self::NAMESPACE_XMLDSIG.'">', '</ds:Signature>'],
            $this->eventoAssinado(),
        );
        $this->assertStringContainsString('<ds:Signature', $prefixado);

        $this->expectException(RuntimeException::class);

        $this->validator()->validate($this->envEventoCom($prefixado));
    }

    public function test_rejeita_desc_evento_fora_da_enumeracao(): void
    {
        $outro = str_replace('Ciencia da Operacao', 'Ciência da Operação', $this->eventoAssinado());

        $this->expectException(RuntimeException::class);

        $this->validator()->validate($this->envEventoCom($outro));
    }

    public function test_rejeita_id_fora_da_regra_do_leiaute(): void
    {
        // `ID` + 52 dígitos é a regra; um `Id` curto é recusado pelo padrão do
        // atributo, mesmo que a assinatura referencie o mesmo valor.
        $curto = str_replace(self::ID_DO_EVENTO, 'ID21021001', $this->eventoAssinado());

        $this->expectException(RuntimeException::class);

        $this->validator()->validate($this->envEventoCom($curto));
    }

    public function test_rejeita_lote_sem_id_lote(): void
    {
        $semLote = str_replace('<idLote>1</idLote>', '', $this->envEventoCom($this->eventoAssinado()));

        $this->expectException(RuntimeException::class);

        $this->validator()->validate($semLote);
    }

    public function test_rejeita_versao_desconhecida_do_lote(): void
    {
        $outraVersao = str_replace(
            '<envEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">',
            '<envEvento xmlns="'.self::NAMESPACE_NFE.'" versao="2.00">',
            $this->envEventoCom($this->eventoAssinado()),
        );

        $this->expectException(RuntimeException::class);

        $this->validator()->validate($outraVersao);
    }

    public function test_rejeita_xml_malformado(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator()->validate('<envEvento versao="1.00"><idLote>1</idLote>');
    }

    public function test_rejeita_o_payload_da_distribuicao_no_schema_de_eventos(): void
    {
        // O mesmo namespace, outro serviço: o schema de eventos não conhece
        // `distDFeInt`, e é o que impede um corpo de consulta de sair pelo
        // caminho da manifestação.
        $this->expectException(RuntimeException::class);

        $this->validator()->validate(
            '<distDFeInt xmlns="'.self::NAMESPACE_NFE.'" versao="1.01"><tpAmb>2</tpAmb>'
            .'<CNPJ>00000000000191</CNPJ><distNSU><ultNSU>000000000000000</ultNSU></distNSU></distDFeInt>',
        );
    }

    private function eventoAssinado(): string
    {
        return self::$eventoAssinado ??= (new EventSigner)->sign(
            $this->eventoDeCiencia(),
            self::$a1['bytes'],
            self::$a1['password'],
        );
    }

    private function validator(): EventEnvelopeValidator
    {
        return app(EventEnvelopeValidator::class);
    }
}
