<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Conector de Distribution DF-e da NF-e.
 *
 * Nenhum teste aqui toca a rede: `preventStrayRequests` faz qualquer chamada
 * fora do `Http::fake()` explodir, e o `Storage::fake` impede que o
 * materializador leia o disco real.
 */
class NfeDistributionConnectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chave de acesso do fixture `retDistDFeInt_138.xml`, com o dígito
     * verificador que o módulo 11 do próprio módulo calcula.
     */
    private const CHAVE = '35220499999999999999550010020000001240556600';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_a_fonte_e_a_distribuicao_de_nfe(): void
    {
        $this->assertSame(FiscalSource::NfeDistribuicao, $this->connector()->source());
    }

    public function test_o_contrato_resolve_para_o_conector_de_nfe(): void
    {
        $this->assertInstanceOf(NfeDistributionConnector::class, resolve(FiscalConnector::class));
    }

    public function test_pull_traz_o_documento_e_adota_a_posicao_da_resposta(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertCount(1, $result->documents);

        $document = $result->documents[0];

        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertSame(self::CHAVE, $document->chave);
        $this->assertSame('', $document->eventId);
        $this->assertSame('99999999999999', $document->emitenteCnpj);
        $this->assertNull($document->destinatarioCnpj);
        $this->assertSame('710.00', $document->valorTotal);
        $this->assertSame(200, $document->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $document->schema);
        $this->assertSame('2022-04-04T11:54:49-03:00', $document->emissaoAt?->toIso8601String());
        $this->assertNull($document->eventoOcorridoEmAt);
        $this->assertStringContainsString('<chNFe>'.self::CHAVE.'</chNFe>', $document->xml);

        $this->assertSame(200, $result->lastNsu);
        $this->assertSame(200, $result->maxNsu);
        $this->assertNull($result->blockedUntil);
        $this->assertFalse($result->more);
    }

    public function test_a_posicao_viaia_com_quinze_digitos_e_nunca_e_incrementada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 200, 50);

        // A posição vai com quinze dígitos e nenhuma soma acontece em lugar
        // nenhum: somar um aqui perderia a posição 201 para sempre, que é o
        // erro que a decisão 5 nomeia.
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<ultNSU>000000000000200</ultNSU>')
            && ! str_contains($request->body(), '<ultNSU>000000000000201</ultNSU>'));

        // E a posição devolvida é a que veio na resposta, não a que foi pedida.
        $this->assertSame(200, $result->lastNsu);
    }

    public function test_pull_avisa_que_ainda_ha_posicao_a_puxar_quando_a_resposta_diz_que_ha(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake([
            '*' => Http::response(
                $this->responseWith('138', 'Documento(s) localizado(s)', 200, 900, [$this->docZipResNFe(200)]),
                200
            ),
        ]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(900, $result->maxNsu);
        $this->assertTrue($result->more);
    }

    public function test_pull_bloqueia_por_uma_hora_quando_nenhum_documento_e_localizado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->documents);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        $this->assertFalse($result->more);
    }

    public function test_o_consumo_indevido_adota_a_posicao_do_corpo_da_rejeicao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_656_com_nsu.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(1678, $result->lastNsu);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
    }

    public function test_a_requisicao_nao_carrega_assinatura(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'Signature')
            && str_contains($request->body(), '<distDFeInt'));
    }

    public function test_nenhum_codigo_de_manifestacao_e_enviado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('NFeDistribuicaoDFe.asmx', $request->url());
            $this->assertStringNotContainsString('210200', $request->body());
            $this->assertStringNotContainsString('Manifestacao', $request->body());

            return true;
        });
    }

    public function test_usa_o_cnpj_e_a_uf_do_proprio_cliente(): void
    {
        $client = $this->clientWithCertificate('12345678000199');

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<CNPJ>12345678000199</CNPJ>')
            && str_contains($request->body(), '<cUFAutor>35</cUFAutor>'));
    }

    public function test_uf_desconhecida_recusa_antes_de_qualquer_chamada(): void
    {
        $client = $this->clientWithCertificate(state: 'XX');

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        // `cUFAutor` é a UF do interessado e o serviço aceita qualquer código da
        // tabela: um valor inventado aqui seria uma afirmação falsa sobre quem
        // pergunta, e nada voltaria para denunciá-la.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não está na tabela de UFs');

        try {
            $this->connector()->pull($client, 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_senha_do_certificado_chega_ao_curl(): void
    {
        $client = $this->clientWithCertificate(password: 'segredo-unico-9f2b');
        $curl = [];

        Http::fake(['*' => function (Request $request, array $options) use (&$curl) {
            $curl = $options['curl'] ?? [];

            return Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200);
        }]);

        $this->connector()->pull($client, 0, 50);

        $this->assertSame('P12', $curl[CURLOPT_SSLCERTTYPE]);
        $this->assertSame('segredo-unico-9f2b', $curl[CURLOPT_SSLCERTPASSWD]);
        $this->assertNotEmpty($curl[CURLOPT_SSLCERT]);
        // O caminho entregue ao `curl` é o arquivo efêmero, e não o cofre: o
        // material temporário já foi apagado quando a requisição termina.
        $this->assertFileDoesNotExist($curl[CURLOPT_SSLCERT]);
    }

    public function test_a_verificacao_do_servidor_apoia_no_bundle_versionado(): void
    {
        $client = $this->clientWithCertificate();
        $guzzle = [];

        Http::fake(['*' => function (Request $request, array $options) use (&$guzzle) {
            $guzzle = $options;

            return Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200);
        }]);

        $this->connector()->pull($client, 0, 50);

        // Verificar o servidor continua ligado: o bundle é a cadeia da
        // ICP-Brasil versionada, não o trust store da máquina e nunca `false`.
        $this->assertSame(config('fiscal.ca_bundle'), $guzzle['verify']);
        $this->assertFileExists($guzzle['verify']);
    }

    public function test_a_acao_soap_viaja_no_content_type(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => $request->header('Content-Type')[0]
            === 'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse"');
    }

    public function test_a_url_segue_o_ambiente_configurado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'www1.nfe.fazenda.gov.br'));

        config(['fiscal.environment' => 'homologacao']);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'hom1.nfe.fazenda.gov.br'));
    }

    public function test_cliente_sem_certificado_recusa_antes_de_qualquer_chamada(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cliente sem certificado A1 vigente.');

        try {
            $this->connector()->pull($client, 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_certificado_sem_senha_armazenada_nao_faz_chamada(): void
    {
        $client = $this->clientWithCertificate(password: 'senha');

        ClientCertificate::query()
            ->where('client_id', $client->getKey())
            ->update(['password_encrypted' => null]);

        $this->assertNull($client->currentCertificate?->certificatePassword());

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A senha do certificado do cliente não está armazenada.');

        try {
            $this->connector()->pull($client->refresh(), 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_fetch_by_chave_consulta_pela_chave_e_traz_o_documento(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $document = $this->connector()->fetchByChave($client, self::CHAVE);

        $this->assertNotNull($document);
        $this->assertSame(self::CHAVE, $document->chave);
        $this->assertSame(200, $document->nsu);

        // Consulta por chave e consulta por posição não podem se confundir: o
        // corpo vai com `consChNFe` e sem nenhum `distNSU`.
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<consChNFe><chNFe>'.self::CHAVE.'</chNFe></consChNFe>')
            && ! str_contains($request->body(), 'distNSU'));
    }

    public function test_fetch_by_chave_devolve_nulo_quando_o_servico_nao_localiza(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->assertNull($this->connector()->fetchByChave($client, self::CHAVE));
    }

    public function test_fetch_by_chave_propaga_o_bloqueio_como_excecao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_656_com_nsu.xml'), 200)]);

        // Devolver `null` aqui diria "esta chave não existe" e mandaria a
        // reconciliação procurar a próxima, gastando o limite horário de
        // consultas com o CNPJ já bloqueado.
        try {
            $this->connector()->fetchByChave($client, self::CHAVE);

            $this->fail('Um consumo indevido deveria virar FiscalException, não ausência de documento.');
        } catch (FiscalException $exception) {
            $this->assertSame(FiscalFailure::Blocked, $exception->failure);
        }
    }

    public function test_fetch_by_chave_recusa_chave_com_dv_invalido_sem_chamar_o_servico(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        // Dígito verificador trocado: a identidade do documento está errada e
        // nenhuma consulta por ela pode devolver a coisa certa.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chave de acesso inválida');

        try {
            $this->connector()->fetchByChave($client, '35220499999999999999550010020000001240556603');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cnpj_sem_correspondencia_vira_falha_de_credencial(): void
    {
        $exception = $this->pullComRejeicao('593');

        $this->assertSame(FiscalFailure::Unauthorized, $exception->failure);
        $this->assertFalse($exception->failure->retryable());
    }

    public function test_rejeicao_de_schema_vira_falha_do_nosso_lado(): void
    {
        $exception = $this->pullComRejeicao('215');

        $this->assertSame(FiscalFailure::Rejected, $exception->failure);
    }

    public function test_indisponibilidade_do_servico_e_retentavel_e_nao_bloqueia(): void
    {
        // `108` é do serviço inteiro, não do CNPJ: um retry, e nunca uma hora
        // de silêncio por cliente.
        $exception = $this->pullComRejeicao('108');

        $this->assertSame(FiscalFailure::Upstream, $exception->failure);
        $this->assertTrue($exception->failure->retryable());
        $this->assertFalse($exception->failure->blocksForAnHour());
    }

    public function test_uma_resposta_que_nao_e_do_servico_e_falha_do_upstream(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502)]);

        try {
            $this->connector()->pull($client, 0, 50);

            $this->fail('Uma resposta fora do contrato do serviço deveria virar FiscalException.');
        } catch (FiscalException $exception) {
            // O status HTTP real é que decide: `502` é `Upstream` e retentável,
            // e um `cStat` viria de um corpo que não existe.
            $this->assertSame(FiscalFailure::Upstream, $exception->failure);
            $this->assertTrue($exception->failure->retryable());
        }
    }

    public function test_uma_falha_de_transporte_e_falha_do_upstream(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => function (): never {
            throw new ConnectionException('Falha na conexão de saída.');
        }]);

        try {
            $this->connector()->pull($client, 0, 50);

            $this->fail('Uma falha de transporte deveria virar FiscalException.');
        } catch (FiscalException $exception) {
            $this->assertSame(FiscalFailure::Upstream, $exception->failure);
            $this->assertTrue($exception->failure->retryable());
        }
    }

    /**
     * Um `Http::fake()` por teste: o registro de stubs acumula a cada chamada,
     * e o primeiro registrado é quem responde.
     */
    private function pullComRejeicao(string $cStat): FiscalException
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith($cStat, 'Rejeicao do teste', 0), 200)]);

        try {
            $this->connector()->pull($client, 0, 50);
        } catch (FiscalException $exception) {
            $this->assertSame('Rejeicao do teste', $exception->getMessage());

            return $exception;
        }

        $this->fail("A rejeicao {$cStat} deveria virar FiscalException.");
    }

    private function connector(): NfeDistributionConnector
    {
        return new NfeDistributionConnector(
            resolve(DfeSoapEnvelope::class),
            resolve(DfeResponseParser::class),
            resolve(DocZipDecoder::class),
            resolve(FiscalXmlMetadata::class),
            resolve(FiscalXmlValidator::class),
            resolve(ClientCertificateMaterializer::class),
        );
    }

    private function clientWithCertificate(
        string $taxId = '00000000000191',
        string $state = 'SP',
        string $password = 'senha',
    ): Client {
        $account = Account::factory()->create();

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => $taxId,
            'state' => $state,
        ]);

        ClientCertificate::factory()->withPassword($password)->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return $client->refresh();
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }

    private function responseWith(
        string $cStat,
        string $xMotivo,
        int $ultNsu,
        ?int $maxNsu = 200,
        array $entries = [],
    ): string {
        $lote = '';

        if ($entries !== []) {
            $lote = '<loteDistDFeInt>'.implode('', $entries).'</loteDistDFeInt>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            .'<nfeDistDFeInteresseResult>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            ."<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            .'<ultNSU>'.str_pad((string) $ultNsu, 15, '0', STR_PAD_LEFT).'</ultNSU>'
            .'<maxNSU>'.str_pad((string) $maxNsu, 15, '0', STR_PAD_LEFT).'</maxNSU>'
            .$lote
            .'</retDistDFeInt></nfeDistDFeInteresseResult>'
            .'</nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>';
    }

    private function docZipResNFe(int $nsu): string
    {
        $document = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<chNFe>'.self::CHAVE.'</chNFe>'
            .'<CNPJ>99999999999999</CNPJ>'
            .'<dhEmi>2022-04-04T11:54:49-03:00</dhEmi>'
            .'<vNF>710.00</vNF>'
            .'</resNFe>';

        return '<docZip NSU="'.str_pad((string) $nsu, 15, '0', STR_PAD_LEFT).'" schema="resNFe_v1.01.xsd">'
            .base64_encode(gzencode($document))
            .'</docZip>';
    }
}
