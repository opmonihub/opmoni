<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Manifestacao\EventEnvelopeValidator;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\ManifestationResult;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsManifestationEvent;
use Tests\TestCase;

/**
 * O conector `nfeRecepcaoEvento`: monta o evento 210210 a partir do resumo,
 * assina com o A1 do cliente, valida o lote contra o XSD local e classifica o
 * cStat que o `retEnvEvento` devolve.
 *
 * Três classificações são o contrato: aceite (`128` no lote + `135` no evento)
 * vira `Sent`; `573` ("já existe evento para esta chave" — inclusive de
 * terceiro) vira `AlreadyManifested`, que é **estado conhecido** e não
 * `FiscalFailure`; e o que não é aceite nem estado conhecido é rejeição que a
 * taxonomia lê — transitória vira `FiscalException` retentável.
 */
class ManifestacaoRecepcaoEventoTest extends TestCase
{
    use BuildsManifestationEvent, RefreshDatabase;

    private const EMISSAO = '2026-09-20T12:00:00-03:00';

    private const SENHA = 'segredo-unico-9f2b';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03T15:00:00-03:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_o_aceite_do_evento_vira_sent_com_cstat_e_motivo(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '135', 'Evento registrado e vinculado a NF-e'), 200)]);

        $this->assertTrue($this->conector()->cienciaDaEmissao(
            $cliente,
            $registro,
            CarbonImmutable::parse(self::EMISSAO),
        ));

        $registro->refresh();

        $this->assertSame(FiscalManifestationOutcome::Sent, $registro->outcome);
        $this->assertSame('135', $registro->result_code);
        $this->assertSame('Evento registrado e vinculado a NF-e', $registro->result_message);
        $this->assertNotNull($registro->sent_at);
        $this->assertNotNull($registro->resulted_at);

        Http::assertSent(function (Request $request): bool {
            // A ação SOAP viaja no `Content-Type`, como na distribuição.
            $this->assertSame(
                'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4/nfeRecepcaoEventoNF"',
                $request->header('Content-Type')[0],
            );

            $this->assertStringContainsString('<envEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">', $request->body());
            $this->assertStringContainsString('<chNFe>'.self::CHAVE_DE_ACESSO.'</chNFe>', $request->body());
            // O evento viaja assinado — a assinatura é requisito do serviço,
            // não um adendo.
            $this->assertStringContainsString('<Signature xmlns="'.self::NAMESPACE_XMLDSIG.'">', $request->body());
            $this->assertStringContainsString('SignatureValue', $request->body());

            return true;
        });
    }

    public function test_a_url_segue_o_ambiente_configurado(): void
    {
        [$cliente] = $this->pedidoRegistrado();

        config(['fiscal.environment' => 'producao']);
        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '135', 'Evento registrado'), 200)]);

        $this->conector()->cienciaDaEmissao($cliente, $this->registro(), CarbonImmutable::parse(self::EMISSAO));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'www.nfe.fazenda.gov.br'));

        config(['fiscal.environment' => 'homologacao']);
        $this->conector()->cienciaDaEmissao($cliente, $this->registro(), CarbonImmutable::parse(self::EMISSAO));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'hom1.nfe.fazenda.gov.br'));
    }

    public function test_ambiente_fora_da_lista_recusa_a_montagem_sem_sair_para_a_rede(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // Um `fiscal.environment` fora da lista — typo, `production`, caixa
        // errada — não pode cair em homologação em silêncio: o evento sairia
        // assinado com o A1 real contra o ambiente errado. A seleção é
        // fail-closed: valor inesperado é recusa antes do primeiro byte.
        config(['fiscal.environment' => 'production']);

        Http::fake(['*' => Http::response('', 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um ambiente fora da lista deveria recusar a montagem.');
        } catch (FiscalRequestNotSent) {
            // esperado: o evento não existe para o fisco.
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_verificacao_do_servidor_apoia_no_bundle_de_eventos(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();
        $options = [];

        Http::fake(['*' => function (Request $request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response($this->retEnvEvento('128', 'ok', '135', 'Evento registrado'), 200);
        }]);

        $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

        // O servidor de eventos usa Let's Encrypt, não ICP-Brasil: o bundle
        // de verificação é o combinado de eventos, que cobre as duas cadeias.
        $this->assertSame(config('fiscal.eventos_ca_bundle'), $options['verify']);
        $this->assertFileExists($options['verify']);
    }

    public function test_a_senha_do_certificado_chega_ao_curl_e_a_ao_evento_tambem_assina(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();
        $options = [];

        Http::fake(['*' => function (Request $request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response($this->retEnvEvento('128', 'ok', '135', 'Evento registrado'), 200);
        }]);

        $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

        // mTLS usa o arquivo efêmero, apagado ao fim da chamada — o mesmo
        // contrato do transporte de distribuição.
        $this->assertSame(self::SENHA, $options['cert'][1] ?? null);
        $this->assertNotEmpty($options['cert'][0] ?? null);
        $this->assertFileDoesNotExist($options['cert'][0]);
    }

    public function test_a_rejeicao_573_e_estado_conhecido_ja_manifestado_nao_falha(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '573', 'Rejeicao: Duplicidade de Evento'), 200)]);

        $this->assertTrue($this->conector()->cienciaDaEmissao(
            $cliente,
            $registro,
            CarbonImmutable::parse(self::EMISSAO),
        ));

        $registro->refresh();

        // O 573 diz "outra manifestação desta chave já foi registrada" — o fim
        // é o mesmo que uma ciência nossa registraria, então é veredito e não
        // rejeição transitória: quem consome segue para o `consChNFe`.
        $this->assertSame(FiscalManifestationOutcome::AlreadyManifested, $registro->outcome);
        $this->assertSame('573', $registro->result_code);
        $this->assertNotNull($registro->resulted_at);
    }

    public function test_rejeicao_transitoria_do_evento_vira_falha_retentavel_do_upstream(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '108', 'Servico paralisado momentaneamente'), 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma rejeição transitória deveria virar FiscalException retentável.');
        } catch (FiscalException $exception) {
            $this->assertTrue($exception->failure->retryable());
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
    }

    public function test_a_rejeicao_definitiva_grava_o_que_o_fisco_respondeu(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // `214` é rejeição definitiva do evento — não retentável e não estado
        // conhecido. A exceção sobe para quem decide retry, mas antes disso o
        // registro leva o `Failed` com o cStat e o motivo: sem eles a
        // auditoria teria um `pending` eterno e nenhuma pista da resposta.
        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '214', 'Rejeicao: Duplicidade de registro'), 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma rejeição definitiva deveria virar FiscalException não retentável.');
        } catch (FiscalException $exception) {
            $this->assertFalse($exception->failure->retryable());
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Failed, $registro->outcome);
        $this->assertSame('214', $registro->result_code);
        $this->assertStringContainsString('Duplicidade', (string) $registro->result_message);
        $this->assertNotNull($registro->resulted_at);
    }

    public function test_lote_recusado_por_indisponibilidade_e_retentavel(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response($this->retEnvEvento('108', 'Servico paralisado momentaneamente'), 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('A indisponibilidade do lote deveria ser retentável.');
        } catch (FiscalException $exception) {
            $this->assertTrue($exception->failure->retryable());
        }
    }

    public function test_um_fault_soap_em_200_e_recusa_retentavel_condensada(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response($this->fault('    Falha    temporaria   do   servico '), 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um fault SOAP deveria ser recusa retentável.');
        } catch (FiscalException $exception) {
            // O texto vai condensado para a mensagem, sem eco do pedido.
            $this->assertStringContainsString('Falha temporaria do servico', $exception->getMessage());
            $this->assertTrue($exception->failure->retryable());
        }
    }

    public function test_uma_falha_de_transporte_e_falha_do_upstream_sem_senha_na_mensagem(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => fn () => throw new ConnectionException('cURL 7 sem saída')]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma falha de transporte deveria ser FiscalException.');
        } catch (FiscalException $exception) {
            $this->assertTrue($exception->failure->retryable());
            $this->assertStringNotContainsString(self::SENHA, $exception->getMessage());
        }
    }

    public function test_um_500_de_proxy_e_falha_do_upstream(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um 502 deveria ser falha do upstream.');
        } catch (FiscalException $exception) {
            $this->assertTrue($exception->failure->retryable());
        }
    }

    public function test_um_200_sem_ret_env_evento_nao_e_aceite(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response('<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><outraCoisa/></soap:Body></soap:Envelope>', 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma resposta fora do contrato não pode virar veredito.');
        } catch (FiscalException) {
            // esperado
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
    }

    public function test_cliente_sem_certificado_recusa_antes_de_qualquer_chamada(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
        ]);
        $registro = $this->registroPersistido($cliente);

        Http::fake(['*' => Http::response('', 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Cliente sem certificado deveria ser recusado antes da chamada.');
        } catch (FiscalRequestNotSent) {
            // esperado: sem mTLS a requisição não existe.
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_o_evento_assinado_no_corpo_passa_no_xsd_local(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();
        $corpo = null;

        Http::fake(['*' => function (Request $request) use (&$corpo) {
            $corpo = $request->body();

            return Http::response($this->retEnvEvento('128', 'ok', '135', 'Evento registrado'), 200);
        }]);

        $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

        // O mesmo XSD que barra um lote malformado antes da rede confere, a
        // posteriori, o que foi para o fio — com o Signature do EventSigner.
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($corpo));
        $envEvento = (new \DOMXPath($dom))->query('//*[local-name()="envEvento"]')->item(0);

        resolve(EventEnvelopeValidator::class)->validate($dom->saveXML($envEvento));
    }

    public function test_nenhum_log_nem_veredito_carrega_senha_xml_ou_envelope(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Log::spy();

        // Falha de transporte com a senha e trechos do evento embarcados no
        // texto da exceção original — exatamente o vazamento que a condensação
        // precisa cortar.
        Http::fake(['*' => function () {
            throw new ConnectionException(
                'falha '.self::SENHA.' <SignatureValue>ABC</SignatureValue> <evento xmlns="http://www.portalfiscal.inf.br/nfe">'
            );
        }]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));
        } catch (FiscalException $exception) {
            $mensagem = $exception->getMessage();
            $this->assertStringNotContainsString(self::SENHA, $mensagem);
            $this->assertStringNotContainsString('SignatureValue', $mensagem);
            $this->assertStringNotContainsString('<evento', $mensagem);
            $this->assertStringNotContainsString('envEvento', $mensagem);
        }

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('alert');
        Log::shouldNotHaveReceived('critical');

        // O veredito gravado também não ecoa nada sensível.
        $registro->refresh();
        $this->assertStringNotContainsString(self::SENHA, (string) $registro->result_message);
        $this->assertNull($registro->result_code);
    }

    public function test_fault_com_detalhe_nao_ecoa_o_pedido_no_veredito(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        Http::fake(['*' => Http::response(
            '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<soap:Fault><faultcode>soap:Server</faultcode>'
            .'<faultstring>assinatura invalida</faultstring>'
            .'<detail><nfeResultMsg><SignatureValue>XYZ</SignatureValue></nfeResultMsg></detail>'
            .'</soap:Fault></soap:Body></soap:Envelope>',
            200,
        )]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));
        } catch (FiscalException $exception) {
            // O `detail` é onde o serviço ecoa o pedido: só o `faultstring`
            // condensado vai para a mensagem, e nada do que o `detail` traz —
            // o evento assinado com `SignatureValue` — pode aparecer.
            $this->assertStringNotContainsString('SignatureValue', $exception->getMessage());
            $this->assertStringNotContainsString('nfeResultMsg', $exception->getMessage());
            $this->assertStringNotContainsString('<envEvento', $exception->getMessage());
        }
    }

    public function test_o_ret_env_evento_for_do_body_nao_e_veredito(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // Um `retEnvEvento` fora do `Body` — num `detail` de fault, num anexo,
        // num header — não é a resposta do serviço. Sem escopo a busca
        // `//retEnvEvento` pega o primeiro em ordem de documento e este aceite
        // de mentira viraria veredito.
        Http::fake(['*' => Http::response(
            '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<retEnvEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote><cOrgao>91</cOrgao><cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo>'
            .'<retEvento versao="1.00"><infEvento>'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            .'<cStat>135</cStat><xMotivo>Evento registrado</xMotivo><dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>'
            .'</retEnvEvento>'
            .'<soap:Body><outraCoisa/></soap:Body></soap:Envelope>',
            200,
        )]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um retEnvEvento fora do Body não pode virar veredito.');
        } catch (FiscalException) {
            // esperado: a resposta não é o retEnvEvento do serviço.
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNull($registro->result_code);
    }

    public function test_o_ret_env_evento_de_um_detail_nao_e_veredito(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // Variante do mesmo caso com o fault estruturado como SOAP 1.1 (code
        // que o `faultOf` não lê): o `retEnvEvento` dentro do `detail` não é a
        // resposta do serviço, por mais bem formado que esteja.
        Http::fake(['*' => Http::response(
            '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<soap:Fault><faultcode>soap:Server</faultcode>'
            .'<detail><retEnvEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote><cOrgao>91</cOrgao><cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo>'
            .'<retEvento versao="1.00"><infEvento>'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            .'<cStat>135</cStat><xMotivo>Evento registrado</xMotivo><dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>'
            .'</retEnvEvento></detail>'
            .'</soap:Fault></soap:Body></soap:Envelope>',
            200,
        )]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um retEnvEvento dentro de detail não pode virar veredito.');
        } catch (FiscalException) {
            // esperado: a resposta não é o retEnvEvento do serviço.
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNull($registro->result_code);
    }

    public function test_a_rejeicao_do_evento_ainda_marca_sent_at(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // A requisição viajou e o fisco respondeu com rejeição: a tentativa
        // saiu e `sent_at` é a marca dela, veredito ou não.
        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '108', 'Servico paralisado momentaneamente'), 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma rejeição transitória deveria virar FiscalException retentável.');
        } catch (FiscalException) {
            // esperado
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNotNull($registro->sent_at);
        $this->assertNull($registro->resulted_at);
    }

    public function test_o_200_sem_ret_env_evento_ainda_marca_sent_at(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // Resposta fora do contrato, mas a chamada saiu e voltou — `sent_at`
        // registra a ida à rede mesmo sem veredito do fisco.
        Http::fake(['*' => Http::response('<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><outraCoisa/></soap:Body></soap:Envelope>', 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Uma resposta fora do contrato não pode virar veredito.');
        } catch (FiscalException) {
            // esperado
        }

        $registro->refresh();
        $this->assertNotNull($registro->sent_at);
    }

    public function test_a_recusa_antes_do_envio_nao_marca_sent_at(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
        ]);
        $registro = $this->registroPersistido($cliente);

        // Sem certificado a requisição morre antes do primeiro byte — e
        // `sent_at` tem que continuar nulo, porque nada foi ao fisco.
        Http::fake(['*' => Http::response('', 200)]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Cliente sem certificado deveria ser recusado antes da chamada.');
        } catch (FiscalRequestNotSent) {
            // esperado
        }

        $registro->refresh();
        $this->assertNull($registro->sent_at);
        Http::assertNothingSent();
    }

    public function test_o_cstat_do_lote_nao_e_o_do_evento(): void
    {
        [$cliente, $registro] = $this->pedidoRegistrado();

        // `retEnvEvento` fora do leiaute: o lote veio sem o próprio `cStat`, e
        // o do `retEvento` não pode ser emprestado para ele — um `128`
        // capturado no lugar errado fingiria um lote processado.
        Http::fake(['*' => Http::response(
            '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/RecepcaoEvento">'
            .'<nfeRecepcaoEventoResult>'
            .'<retEnvEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote><tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            .'<retEvento versao="1.00"><infEvento><tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            .'<cStat>135</cStat><xMotivo>Evento registrado</xMotivo><dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>'
            .'</retEnvEvento></nfeRecepcaoEventoResult></nfeRecepcaoEventoResponse></soap:Body></soap:Envelope>',
            200,
        )]);

        try {
            $this->conector()->cienciaDaEmissao($cliente, $registro, CarbonImmutable::parse(self::EMISSAO));

            $this->fail('Um lote sem cStat próprio não pode ser lido como processado.');
        } catch (FiscalException) {
            // esperado: a recusa é do lote, não do evento.
        }

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNull($registro->result_code);
    }

    public function test_a_classificacao_e_de_instancia_com_o_veredito_do_evento(): void
    {
        $resultado = resolve(ManifestationResult::class);

        $this->assertSame(FiscalManifestationOutcome::Sent, $resultado->classify('128', 'ok', '135', 'Evento registrado')->outcome);
        $this->assertSame(FiscalManifestationOutcome::AlreadyManifested, $resultado->classify('128', 'ok', '573', 'Duplicidade')->outcome);
    }

    /**
     * @return array{0: Client, 1: FiscalManifestation}
     */
    private function pedidoRegistrado(): array
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
        ]);

        ClientCertificate::factory()->withPassword(self::SENHA)->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
        ]);

        $registro = $this->registroPersistido($cliente);

        return [$cliente->refresh(), $registro];
    }

    private function registroPersistido(Client $cliente): FiscalManifestation
    {
        $registro = resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: self::CHAVE_DE_ACESSO,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'teste',
        );

        return $registro->refresh();
    }

    private function registro(): FiscalManifestation
    {
        return FiscalManifestation::withoutGlobalScope('account')->latest('id')->firstOrFail();
    }

    private function conector(): RecepcaoEventoConnector
    {
        return resolve(RecepcaoEventoConnector::class);
    }

    /**
     * O `retEnvEvento` do serviço, com ou sem o `retEvento` interno.
     */
    private function retEnvEvento(string $cStatLote, string $xMotivoLote, ?string $cStatEvento = null, ?string $xMotivoEvento = null): string
    {
        $retEvento = $cStatEvento === null ? '' : '<retEvento versao="1.00">'
            .'<infEvento>'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatEvento}</cStat><xMotivo>{$xMotivoEvento}</xMotivo>"
            .'<dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/RecepcaoEvento">'
            .'<nfeRecepcaoEventoResult>'
            .'<retEnvEvento xmlns="'.self::NAMESPACE_NFE.'" versao="1.00">'
            .'<idLote>1</idLote><tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>{$xMotivoLote}</xMotivo>"
            .$retEvento
            .'</retEnvEvento></nfeRecepcaoEventoResult>'
            .'</nfeRecepcaoEventoResponse></soap:Body></soap:Envelope>';
    }

    private function fault(string $text): string
    {
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<soap:Fault><faultcode>soap:Server</faultcode>'
            ."<faultstring>{$text}</faultstring>"
            .'</soap:Fault></soap:Body></soap:Envelope>';
    }
}
