<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Nfse\NfseAdnConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Tests\TestCase;

/**
 * Conector da API REST de contribuintes da ADN NFS-e.
 *
 * O shape do JSON foi observado pelo `fiscal:nfse-probe` no canário Auto
 * Center (produção, HTTP 200, NSU 0): os campos de topo, os nomes de campo do
 * item e o XML cru de `ArquivoXml` são a fixture `lote-real.json`,
 * anonimizada. O que segue hipótese está marcado nas fixtures `-hipotese`
 * (o texto do status de fim de fila, que o canário não observou).
 *
 * Nenhum teste aqui toca a rede: `preventStrayRequests` faz qualquer chamada
 * fora do `Http::fake()` explodir, e o `Storage::fake` impede que o
 * materializador leia o disco real.
 */
class NfseAdnConnectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaves de 50 posições com o dígito verificador que o módulo 11 do
     * próprio módulo calcula — a mesma conta de
     * `FiscalXmlMetadata::isValidChave()`. O canário confirmou a fórmula: as
     * 31 chaves distintas da resposta real fecham o dígito.
     */
    private const CHAVE_200 = '35260911222333000181000100000012345678901234567892';

    private const CHAVE_201 = '42060922122444000282000200000098765432109876543212';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();

        // O orçamento de consultas pontuais mora no cache: um contador que
        // sobrevive de um teste para o outro transformaria o teto em número
        // de teste.
        Cache::store('array')->clear();
    }

    public function test_a_fonte_e_a_adn_nfse(): void
    {
        $this->assertSame(FiscalSource::NfseAdn, $this->connector()->source());
    }

    public function test_o_registro_resolve_o_conector_de_nfse_para_a_fonte_de_nfse(): void
    {
        $registry = resolve(FiscalConnectorRegistry::class);

        $this->assertInstanceOf(NfseAdnConnector::class, $registry->for(FiscalSource::NfseAdn));
        $this->assertSame(FiscalSource::NfseAdn, $registry->for(FiscalSource::NfseAdn)->source());
    }

    /**
     * A resposta real do canário inteira, na ordem em que o serviço entregou:
     * 31 `NFSE` e 5 `EVENTO`, todos viram documento, e a posição do lote é a
     * maior NSU devolvida — não a local somada de um.
     */
    public function test_pull_traz_os_documentos_e_a_maior_nsu_devolvida(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $itens = $this->loteReal()['LoteDFe'];

        $this->assertCount(count($itens), $result->documents);
        $this->assertSame([], $result->failures);

        $primeiroNfse = $itens[0];

        $this->assertSame('NFSE', $primeiroNfse['TipoDocumento']);

        $documento = $result->documents[0];

        $this->assertSame(FiscalModel::Nfse, $documento->model);
        $this->assertSame(FiscalKind::Document, $documento->kind);
        $this->assertSame(FiscalStage::Document, $documento->stage);
        $this->assertSame($primeiroNfse['ChaveAcesso'], $documento->chave);
        $this->assertSame(50, strlen($documento->chave));
        $this->assertSame('', $documento->eventId);
        $this->assertSame($primeiroNfse['NSU'], $documento->nsu);

        $this->assertSame(max(array_column($itens, 'NSU')), $result->lastNsu);
        $this->assertTrue($result->mayAdoptPosition);
        $this->assertNull($result->blockedUntil);
        $this->assertNull($result->failure);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://adn.nfse.gov.br/contribuintes/DFe/0');
    }

    /**
     * O evento da NFS-e nacional não tem `tpEvento` em elemento nenhum: o
     * código vem no sufixo do `Id` do `infEvento`, depois da chave de 50
     * posições. O parser o reconhece como evento, com a chave da nota
     * referenciada (`chNFSe`) e o identificador na tipagem da NT.
     */
    public function test_o_evento_da_adn_e_lido_como_evento_com_o_id_do_inf_evento(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $itens = $this->loteReal()['LoteDFe'];
        $itemEvento = null;

        foreach ($itens as $item) {
            if ($item['TipoDocumento'] === 'EVENTO') {
                $itemEvento = $item;

                break;
            }
        }

        $this->assertNotNull($itemEvento);

        $evento = null;

        foreach ($result->documents as $documento) {
            if ($documento->nsu === $itemEvento['NSU']) {
                $evento = $documento;

                break;
            }
        }

        $this->assertNotNull($evento);
        $this->assertSame(FiscalKind::Event, $evento->kind);
        $this->assertSame(FiscalStage::Event, $evento->stage);
        $this->assertSame($itemEvento['ChaveAcesso'], $evento->chave);
        $this->assertMatchesRegularExpression('/^\d{6}-\d{1,10}$/', $evento->eventId);
        $this->assertNotNull($evento->eventoOcorridoEmAt);
    }

    public function test_a_posicao_do_lote_vai_no_caminho_e_nunca_e_incrementada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $this->connector()->pull($client, 900, 50);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://adn.nfse.gov.br/contribuintes/DFe/900');
    }

    public function test_a_url_segue_o_ambiente_configurado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('fila-vazia-hipotese.json'), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'adn.nfse.gov.br'));

        config(['fiscal.environment' => 'homologacao']);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'adn.producaorestrita.nfse.gov.br'));
    }

    /**
     * O fim de fila da ADN é a mesma regra do `137` da distribuição SOAP:
     * bloqueia uma hora e não adota a posição — o eco da posição pedida não é
     * posição nova.
     */
    public function test_o_lote_vazio_bloqueia_por_uma_hora_sem_adotar_posicao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('fila-vazia-hipotese.json'), 200)]);

        $result = $this->connector()->pull($client, 900, 50);

        $this->assertSame([], $result->documents);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        $this->assertSame(FiscalFailure::NoDocuments, $result->failure);
        // A posição armazenada fica intacta: o eco da posição pedida não é
        // posição que a resposta confirmou.
        $this->assertSame(900, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
        $this->assertFalse($result->more);
    }

    /**
     * O status HTTP da ADN engana: `404` com corpo de negócio é "não há
     * documento naquela posição", resposta classificável — e não exceção de
     * rede. A regra de cursor é a mesma de uma resposta vazia explícita.
     */
    public function test_um_404_com_corpo_de_negocio_e_resposta_classificavel_e_nao_excecao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('posicao-nao-localizada-404-hipotese.json'), 404)]);

        $result = $this->connector()->pull($client, 300, 50);

        $this->assertSame([], $result->documents);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        $this->assertSame(300, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_um_404_sem_corpo_de_negocio_e_falha_classificada_pelo_status(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response('<html>not found</html>', 404)]);

        try {
            $this->connector()->pull($client, 0, 50);

            $this->fail('Um corpo que não é resposta do serviço deveria virar FiscalException.');
        } catch (FiscalException $exception) {
            // `4xx` sem corpo de serviço é rejeição do serviço — classificada,
            // e não exceção de transporte genérica.
            $this->assertSame(FiscalFailure::Rejected, $exception->failure);
            $this->assertFalse($exception->failure->retryable());
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

    public function test_uma_entrada_corrompida_e_registrada_e_a_posicao_nao_e_adotada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response(json_encode([
            'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [
                // Chave com dígito verificador inválido: o payload abre, e é a
                // extração de metadados que recusa.
                ['NSU' => 300, 'TipoDocumento' => 'NFSE', 'ArquivoXml' => $this->nfseXml(self::CHAVE_200.'1')],
                ['NSU' => 301, 'TipoDocumento' => 'NFSE', 'ArquivoXml' => $this->nfseXml(self::CHAVE_201)],
            ],
        ], JSON_THROW_ON_ERROR), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([301], array_column($result->documents, 'nsu'));
        $this->assertCount(1, $result->failures);
        $this->assertSame(300, $result->failures[0]->nsu);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $result->failures[0]->reason);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_a_consulta_por_posicao_traz_o_documento_e_gasta_o_orcamento(): void
    {
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 19; $tentativa++) {
            $budget->reserve($client);
        }

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $nsuDoPrimeiro = $this->loteReal()['LoteDFe'][0]['NSU'];

        $document = $this->connector()->fetchByNsu($client, $nsuDoPrimeiro);

        $this->assertNotNull($document);
        $this->assertSame($this->loteReal()['LoteDFe'][0]['ChaveAcesso'], $document->chave);
        $this->assertSame($nsuDoPrimeiro, $document->nsu);

        // A vigésima consulta ainda sai, e é a última: a vaga do teto foi
        // debitada pela consulta que saiu.
        $this->assertFalse($budget->reserve($client));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://adn.nfse.gov.br/contribuintes/DFe/'.$nsuDoPrimeiro);
    }

    public function test_a_consulta_por_posicao_e_adiada_sem_chamar_o_servico_quando_o_orcamento_acaba(): void
    {
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 20; $tentativa++) {
            $budget->reserve($client);
        }

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Consulta pontual sem reserva deveria ser adiada.');
        } catch (FiscalLookupDeferred $exception) {
            $this->assertSame('Limite horário de consultas pontuais atingido.', $exception->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_consulta_por_posicao_devolve_nulo_quando_a_adn_nao_localiza(): void
    {
        // O 404 com corpo de negócio, na consulta pontual, é o "o serviço diz
        // que não há documento naquela posição" — e `null` é a resposta que
        // fecha o laço na reconciliação.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('posicao-nao-localizada-404-hipotese.json'), 404)]);

        $this->assertNull($this->connector()->fetchByNsu($client, 100));
    }

    public function test_a_consulta_por_chave_valida_a_chave_antes_de_qualquer_chamada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chave de acesso inválida');

        try {
            $this->connector()->fetchByChave($client, self::CHAVE_200.'9');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cliente_sem_certificado_recusa_antes_de_qualquer_chamada(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $this->expectException(FiscalRequestNotSent::class);
        $this->expectExceptionMessage('Cliente sem certificado A1 vigente.');

        try {
            $this->connector()->pull($client, 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_o_certificado_e_o_trust_store_do_servidor_chegam_ao_transporte(): void
    {
        $client = $this->clientWithCertificate(password: 'segredo-unico-9f2b');
        $guzzle = [];

        Http::fake(['*' => function (Request $request, array $options) use (&$guzzle) {
            $guzzle = $options;

            return Http::response($this->fixture('fila-vazia-hipotese.json'), 200);
        }]);

        $this->connector()->pull($client, 0, 50);

        // A verificação do servidor é o trust store do sistema: o canário
        // observou cadeia Let's Encrypt no front do ADN — não ICP-Brasil —, e
        // o bundle versionado de `fiscal.ca_bundle` não contém essas raízes.
        // O A1 do cliente no mTLS continua sendo a autenticação: a ADN
        // identifica o contribuinte pelo certificado.
        $this->assertTrue($guzzle['verify']);
        $this->assertSame('segredo-unico-9f2b', $guzzle['cert'][1] ?? null);
        $this->assertNotEmpty($guzzle['cert'][0] ?? null);

        // O caminho entregue ao cliente HTTP é o arquivo efêmero, e não o
        // cofre: o material temporário já foi apagado quando a requisição
        // termina.
        $this->assertFileDoesNotExist($guzzle['cert'][0]);
    }

    private function connector(): NfseAdnConnector
    {
        return resolve(NfseAdnConnector::class);
    }

    private function clientWithCertificate(
        string $taxId = '00000000000191',
        string $password = 'senha',
    ): Client {
        $account = Account::factory()->create();

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => $taxId,
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword($password)->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return $client->refresh();
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/nfse-adn/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }

    /**
     * A resposta real do canário, decodificada — as expectativas derivam dela,
     * e não de números fixados à mão.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function loteReal(): array
    {
        $conteudo = $this->fixture('lote-real.json');

        return json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * XML sintético da NFS-e nacional, com a raiz e o caminho da chave do
     * catálogo de `FiscalXmlMetadata`.
     */
    private function nfseXml(string $chave): string
    {
        return '<NFSe versao="1.00">'
            .'<infNFSe Id="NFSe'.$chave.'">'
            .'<emit><CNPJ>99999999999999</CNPJ></emit>'
            .'<valores><vLiq>710.00</vLiq></valores>'
            .'<dhEmi>2026-10-01T10:00:00-03:00</dhEmi>'
            .'<chNFSe>'.$chave.'</chNFSe>'
            .'<nNFSe>123</nNFSe>'
            .'</infNFSe></NFSe>';
    }
}
