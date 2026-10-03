<?php

namespace Tests\Unit;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Manifestacao\ManifestationEventBuilder;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\BuildsManifestationEvent;
use Tests\TestCase;

/**
 * O prazo legal de 90 dias para a ciência da emissão (NT 2014.002) é checado
 * antes de qualquer byte: fora do prazo o evento não é montado, não é assinado
 * e não sai — o registro fica com o veredito `DeadlineMissed` para quem
 * reconcilia ler, e a tentativa não é cobrada do fisco.
 *
 * O mesmo arquivo cobre a montagem do evento 210210 a partir do resumo
 * capturado (chave, autor e emissão), porque o prazo só faz sentido sobre um
 * evento que respeita o leiaute que o XSD `envConfRecebto` exige.
 */
class ManifestacaoCienciaPrazoTest extends TestCase
{
    use BuildsManifestationEvent;

    private const EMISSAO = '2026-10-03T12:00:00-03:00';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_o_evento_montado_respeita_o_leiaute_do_210210(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03T15:30:00-03:00'));

        config(['fiscal.environment' => 'producao']);

        $evento = $this->builder()->evento(
            chaveAcesso: self::CHAVE_DE_ACESSO,
            autor: '00000000000191',
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($evento));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NAMESPACE_NFE);

        // O `Id` é a referência que a assinatura vai assinar: `ID` + tpEvento +
        // chave + nSeqEvento com dois dígitos.
        $infEvento = $xpath->query('/nfe:evento/nfe:infEvento')->item(0);
        $this->assertSame('ID210210'.self::CHAVE_DE_ACESSO.'01', $infEvento->getAttribute('Id'));

        $texto = fn (string $nome): ?string => $xpath->query("/nfe:evento/nfe:infEvento/nfe:{$nome}")->item(0)?->textContent;

        $this->assertSame('91', $texto('cOrgao'));
        // Produção é `1`; a escolha vem de `fiscal.environment`, nunca do que o
        // resumo traz.
        $this->assertSame('1', $texto('tpAmb'));
        $this->assertSame('00000000000191', $texto('CNPJ'));
        $this->assertSame(self::CHAVE_DE_ACESSO, $texto('chNFe'));
        // O `dhEvento` é o instante no fuso do Brasil (-03:00), como o fisco
        // espera — o teste congela `now()` em 15:30 com o fuso de São Paulo,
        // que é o que viaja no evento.
        $this->assertSame('2026-10-03T15:30:00-03:00', $texto('dhEvento'));
        $this->assertSame('210210', $texto('tpEvento'));
        $this->assertSame('1', $texto('nSeqEvento'));
        $this->assertSame('1.00', $texto('verEvento'));
        $this->assertSame('Ciencia da Operacao', $xpath->query('/nfe:evento/nfe:infEvento/nfe:detEvento/nfe:descEvento')->item(0)?->textContent);
        $this->assertSame('1.00', $xpath->query('/nfe:evento/nfe:infEvento/nfe:detEvento')->item(0)?->getAttribute('versao'));
        $this->assertSame('1.00', $dom->documentElement->getAttribute('versao'));
    }

    public function test_o_tp_amb_segue_o_ambiente_configurado(): void
    {
        config(['fiscal.environment' => 'homologacao']);

        $evento = $this->builder()->evento(
            chaveAcesso: self::CHAVE_DE_ACESSO,
            autor: '00000000000191',
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $evento);
    }

    public function test_o_autor_sem_documento_recusa_antes_de_montar(): void
    {
        $this->expectException(FiscalRequestNotSent::class);

        $this->builder()->evento(
            chaveAcesso: self::CHAVE_DE_ACESSO,
            autor: null,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );
    }

    public function test_chave_com_dv_invalido_recusa_antes_de_montar(): void
    {
        $this->expectException(FiscalRequestNotSent::class);

        $this->builder()->evento(
            chaveAcesso: substr(self::CHAVE_DE_ACESSO, 0, 43).'9',
            autor: '00000000000191',
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );
    }

    public function test_documento_dentro_do_prazo_de_noventa_dias_passa_na_guarda(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-20T10:00:00-03:00'));

        $registro = $this->registroStub();
        $cliente = $this->clienteStub();

        $conector = $this->conectorRecusandoNoCertificado();

        // Chega até a guarda do certificado — a do prazo, anterior, deixou passar.
        $this->expectException(FiscalRequestNotSent::class);
        $this->expectExceptionMessage('certificado');

        try {
            $conector->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));
        } finally {
            $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
            Http::assertNothingSent();
        }
    }

    public function test_documento_fora_do_prazo_nao_sai_e_fica_com_o_veredito_de_prazo_perdido(): void
    {
        // Emissão de 03/10/2026; 90 dias depois o fisco não registra mais a
        // ciência — mandar assim mesmo é a rejeição que custa uma tentativa.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-01T10:00:00-03:00'));

        $registro = $this->registroStub();
        $cliente = $this->clienteStub();

        $this->assertTrue($this->conectorSemCertificado()->cienciaDaEmissao(
            $cliente,
            $registro,
            CarbonImmutable::parse(self::EMISSAO),
        ));

        $this->assertSame(FiscalManifestationOutcome::DeadlineMissed, $registro->outcome);
        $this->assertNotNull($registro->resulted_at);
        Http::assertNothingSent();
    }

    public function test_documento_sem_emissao_registrada_e_recusado_como_prazo_impossivel_de_conferir(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03T15:30:00-03:00'));

        $registro = $this->registroStub();
        $cliente = $this->clienteStub();

        $this->assertTrue($this->conectorSemCertificado()->cienciaDaEmissao(
            $cliente,
            $registro,
            null,
        ));

        $this->assertSame(FiscalManifestationOutcome::DeadlineMissed, $registro->outcome);
        Http::assertNothingSent();
    }

    private function builder(): ManifestationEventBuilder
    {
        return resolve(ManifestationEventBuilder::class);
    }

    /**
     * Um conector cujo materializador para na guarda do certificado — é ela que
     * prova que a guarda do prazo, que vem antes, deixou o pedido seguir.
     */
    private function conectorRecusandoNoCertificado(): RecepcaoEventoConnector
    {
        $conector = resolve(RecepcaoEventoConnector::class);

        Http::fake(['*' => Http::response('', 200)]);

        return $conector;
    }

    private function conectorSemCertificado(): RecepcaoEventoConnector
    {
        Http::fake(['*' => Http::response('', 200)]);

        return resolve(RecepcaoEventoConnector::class);
    }

    /**
     * O prazo é lógica de serviço sobre o registro: um modelo solto, nunca
     * persistido, evita banco no teste de unidade.
     */
    private function registroStub(): FiscalManifestation
    {
        return new FiscalManifestation([
            'client_id' => 1,
            'chave_acesso' => self::CHAVE_DE_ACESSO,
            'event_type' => FiscalManifestationEventType::CienciaEmissao,
            'event_seq' => 1,
            'requested_by' => 'teste',
            'outcome' => FiscalManifestationOutcome::Pending,
            'requested_at' => now(),
        ]);
    }

    /**
     * `currentCertificate` vazio de propósito: a guarda do certificado é a
     * fronteira seguinte à do prazo.
     */
    private function clienteStub(): Client
    {
        $cliente = new Client;
        $cliente->setAttribute('tax_id', '00000000000191');
        $cliente->setRelation('currentCertificate', null);

        return $cliente;
    }

    private function certificadoStub(): ClientCertificate
    {
        return new ClientCertificate;
    }
}
