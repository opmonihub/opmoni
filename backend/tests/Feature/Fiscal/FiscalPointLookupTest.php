<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\Fiscal\Support\XmlQuery;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Consulta pontual de uma posição específica e o teto horário que a cerca.
 *
 * A consulta por posição é a que fecha buraco: pede uma posição, não um lote.
 * O que ela carrega junto é o teto por CNPJ — o fisco bloqueia quem consome de
 * mais, e um laço de reconciliação disparando consulta por posição é
 * exatamente o que provocaria esse bloqueio.
 *
 * Nenhum teste aqui toca a rede: `preventStrayRequests` faz qualquer chamada
 * fora do `Http::fake()` explodir, e o `Storage::fake` impede que o
 * materializador leia o disco real.
 */
class FiscalPointLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chave de acesso do fixture `retDistDFeInt_138_consNSU.xml`, com o dígito
     * verificador que o módulo 11 do próprio módulo calcula. O mesmo documento
     * aparece na fixture do lote, `retDistDFeInt_138.xml`, na posição 200.
     */
    private const CHAVE = '35220499999999999999550010020000001240556600';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();

        // O orçamento mora no cache: um contador que sobrevive de um teste para
        // o outro transformaria o teto em número de teste.
        Cache::store('array')->clear();
    }

    public function test_a_consulta_por_posicao_vai_com_consnsu_e_traz_o_documento(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consNSU.xml'), 200)]);

        $document = $this->connector()->fetchByNsu($client, 100);

        $this->assertNotNull($document);
        $this->assertSame(self::CHAVE, $document->chave);

        // O documento devolvido é o da posição pedida. A fixture é a da forma
        // `consNSU` — uma entrada só, na posição pedida — porque é isso que o
        // serviço responde; a fixture do lote devolveria o documento da posição
        // 200 para um pedido de 100, e o teste passaria fixando como contrato
        // uma coisa que o serviço nunca faz.
        $this->assertSame(100, $document->nsu);

        Http::assertSent(function (Request $request): bool {
            // A posição pedida vai no grupo de consulta por posição, e nenhum
            // outro grupo de consulta pode sobrar: um `distNSU` esquecido aqui
            // voltaria com um lote inteiro, que é a consulta que quem pediu o
            // buraco não fez.
            $this->assertStringContainsString('<consNSU><NSU>000000000000100</NSU></consNSU>', $request->body());
            $this->assertStringNotContainsString('distNSU', $request->body());
            $this->assertStringNotContainsString('consChNFe', $request->body());

            return true;
        });
    }

    public function test_a_consulta_por_posicao_e_aceita_pelo_schema_versionado(): void
    {
        // `consNSU` é elemento do `distDFeInt_v1.01.xsd` ao lado do `distNSU`, e
        // é por isso que a consulta por posição não precisa de schema novo: ela
        // passa pelo mesmo `validate()` do caminho incremental.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consNSU.xml'), 200)]);

        $this->connector()->fetchByNsu($client, 100);

        Http::assertSent(function (Request $request): bool {
            $dom = new DOMDocument;
            $dom->loadXML($request->body());

            (new FiscalXmlValidator)->validate(
                $dom->saveXML(XmlQuery::first(new DOMXPath($dom), 'distDFeInt')),
                'distDFeInt',
                'nfe',
                '1.01',
            );

            return true;
        });
    }

    public function test_a_consulta_por_posicao_preserva_a_acao_soap_e_o_material_do_certificado(): void
    {
        $client = $this->clientWithCertificate(password: 'segredo-unico-9f2b');
        $guzzle = [];

        Http::fake(['*' => function (Request $request, array $options) use (&$guzzle) {
            $guzzle = $options;

            return Http::response($this->fixture('retDistDFeInt_138_consNSU.xml'), 200);
        }]);

        $this->connector()->fetchByNsu($client, 100);

        // A consulta por posição é a mesma chamada SOAP com outro corpo: trocar o
        // grupo de consulta não pode virar outra requisição no transporte.
        Http::assertSent(fn (Request $request): bool => $request->header('Content-Type')[0]
            === 'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse"');

        $this->assertSame(config('fiscal.ca_bundle'), $guzzle['verify']);
        $this->assertSame('segredo-unico-9f2b', $guzzle['cert'][1] ?? null);
        $this->assertNotEmpty($guzzle['cert'][0] ?? null);
    }

    public function test_a_consulta_por_posicao_nao_move_a_posicao_armazenada(): void
    {
        // Quem fecha buraco é a reconciliação, e ela não anda com o cursor: a
        // posição parada em 300 é a posição que a captura incremental soube
        // alcançar, e uma consulta pontual que a alterasse inventaria avanço.
        $client = $this->clientWithCertificate();

        $cursor = FiscalCursor::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'last_nsu' => 300,
        ]);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consNSU.xml'), 200)]);

        $this->connector()->fetchByNsu($client, 100);

        $this->assertSame(300, $cursor->refresh()->last_nsu);
        $this->assertDatabaseCount('fiscal_documents', 0);
    }

    public function test_a_consulta_por_posicao_devolve_nulo_quando_o_servico_nao_localiza(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_137.xml'), 200)]);

        $this->assertNull($this->connector()->fetchByNsu($client, 100));
    }

    public function test_a_consulta_por_posicao_nao_chama_ausencia_quando_o_cnpj_esta_bloqueado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_656_com_nsu.xml'), 200)]);

        // `null` aqui diria "não há documento nesta posição" e mandaria a
        // reconciliação marcar a posição como resolvida, com o CNPJ bloqueado
        // pelo fisco e o buraco intacto.
        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Um consumo indevido deveria virar FiscalException, não ausência de documento.');
        } catch (FiscalException $exception) {
            $this->assertSame(FiscalFailure::Blocked, $exception->failure);
        }
    }

    public function test_uma_posicao_que_o_schema_recusa_nunca_sai_para_a_rede(): void
    {
        // Posição negativa vira `0000000000000-1`, e o `TNSU` do XSD exige
        // quinze dígitos: a validação local barra o pedido inteiro antes de
        // qualquer byte, sem gastar o teto de consultas do CNPJ com uma
        // consulta que o serviço rejeitaria do mesmo jeito.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requisição rejeitada pelo schema');

        try {
            $this->connector()->fetchByNsu($client, -1);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_o_envelope_sem_grupo_de_posicao_unico_e_recusado(): void
    {
        $envelope = $this->envelopeFor($this->clientWithCertificate());
        $position = '<distNSU><ultNSU>000000000000000</ultNSU></distNSU>';

        // A conversão do corpo que o caminho incremental monta é a conversão
        // que a consulta por posição usa, e ela entrega o grupo de consulta por
        // posição no lugar do grupo incremental.
        $converted = resolve(DfeSoapEnvelope::class)->pointNsu($envelope, 100);

        $this->assertStringContainsString('<consNSU><NSU>000000000000100</NSU></consNSU>', $converted);
        $this->assertStringNotContainsString('distNSU', $converted);

        // Um corpo sem grupo de posição e um corpo com dois não têm troca
        // única, e uma reescrita silenciosa nos dois enviaria a consulta errada:
        // o `distNSU` da posição zero, que é o lote inteiro com cara de
        // resposta certa.
        $semGrupo = str_replace($position, '', $envelope);
        $comDoisGrupos = str_replace('</distDFeInt>', $position.'</distDFeInt>', $envelope);

        foreach ([$semGrupo, $comDoisGrupos] as $body) {
            try {
                resolve(DfeSoapEnvelope::class)->pointNsu($body, 100);

                $this->fail('Um envelope sem um único grupo de posição deveria ser recusado.');
            } catch (RuntimeException $exception) {
                $this->assertSame('O envelope não pôde ser convertido em consulta por posição.', $exception->getMessage());
            }
        }
    }

    public function test_o_orcamento_cede_vinte_consultas_e_adia_a_vigesima_primeira(): void
    {
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->assertTrue($budget->reserve($client), "Consulta {$attempt} deveria estar liberada.");
        }

        $this->assertFalse($budget->reserve($client));
    }

    public function test_o_limite_horario_vem_da_configuracao_e_nao_de_um_numero_escrito_no_codigo(): void
    {
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        config(['fiscal.consulta_hourly_limit' => 2]);

        $this->assertTrue($budget->reserve($client));
        $this->assertTrue($budget->reserve($client));
        $this->assertFalse($budget->reserve($client));
    }

    public function test_o_mesmo_cnpj_de_outra_conta_gasta_o_mesmo_orcamento(): void
    {
        // A identidade do teto é o CNPJ e não a conta: quem pergunta ao fisco é o
        // mesma pessoa jurídica, e o fisco não sabe — nem pergunta — de qual
        // escritório a consulta saiu.
        $budget = resolve(FiscalLookupBudget::class);
        $primeira = $this->clientWithCertificate();
        $outraConta = $this->clientWithCertificate();

        $this->assertNotSame($primeira->account_id, $outraConta->account_id);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->assertTrue($budget->reserve($primeira));
        }

        $this->assertFalse($budget->reserve($outraConta));
    }

    public function test_outro_cnpj_nao_herda_o_orcamento_do_primeiro(): void
    {
        $budget = resolve(FiscalLookupBudget::class);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->assertTrue($budget->reserve($this->clientWithCertificate()));
        }

        $this->assertTrue($budget->reserve($this->clientWithCertificate('11222333000181')));
    }

    public function test_a_janela_do_orcamento_renova_na_hora_seguinte(): void
    {
        // A janela é a hora, e não uma hora contada a partir da primeira
        // consulta: o fisco zera a contagem no topo da hora, e um contador que
        // nascesse junto com a primeira consulta da hora ficaria desalinhado
        // dele por até uma hora inteira.
        $this->travelTo(now()->startOfDay()->setTime(10, 50));

        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 20; $tentativa++) {
            $budget->reserve($client);
        }

        $this->assertFalse($budget->reserve($client));

        // Nove minutos depois ainda é a mesma hora, e o contador é o mesmo.
        $this->travel(9)->minutes();

        $this->assertFalse($budget->reserve($client));

        // Onze minutos depois virou a hora seguinte, e a janela é outra.
        $this->travel(2)->minutes();

        $this->assertTrue($budget->reserve($client));
    }

    public function test_uma_consulta_por_posicao_que_sai_gasta_uma_vaga(): void
    {
        // A vaga é gasta pela consulta que sai, não pela que é recusada: um
        // contador debitado só na falha deixaria passar uma consulta a mais com
        // o teto já no limite.
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 19; $tentativa++) {
            $budget->reserve($client);
        }

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consNSU.xml'), 200)]);

        // A vigésima consulta ainda sai, e é a última.
        $this->assertNotNull($this->connector()->fetchByNsu($client, 100));

        $this->assertFalse($budget->reserve($client));
    }

    public function test_a_consulta_por_posicao_e_adiada_sem_chamar_o_servico_quando_o_orcamento_acaba(): void
    {
        $client = $this->clientWithCertificate();

        $this->esgotaOrcamento($client);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Consulta pontual sem reserva deveria ser adiada.');
        } catch (FiscalLookupDeferred $exception) {
            // A mensagem é fixa e não carrega nada do fisco: quem adia precisa
            // saber só que adiou, e o painel mostra a frase.
            $this->assertSame('Limite horário de consultas pontuais atingido.', $exception->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_consulta_por_chave_gasta_o_mesmo_orcamento(): void
    {
        $client = $this->clientWithCertificate();

        $this->esgotaOrcamento($client);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $this->expectException(FiscalLookupDeferred::class);

        try {
            $this->connector()->fetchByChave($client, self::CHAVE);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_trava_do_cnpj_ocupada_adiada_sem_gastar_a_vaga(): void
    {
        // A chave da trava é o CNPJ, e ela é compartilhada entre contas de
        // propósito: duas consultas do mesmo cliente ao mesmo tempo é condição
        // prevista, não exceção. Quem não consegue a trava no tempo da espera
        // não consulta, e não gasta a vaga — a consulta não saiu.
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 19; $tentativa++) {
            $budget->reserve($client);
        }

        $trava = Cache::lock('fiscal:consulta:'.$client->tax_id, 5);

        $this->assertTrue($trava->get(), 'O teste precisa segurar a trava que o orçamento usa.');

        try {
            $this->assertFalse($budget->reserve($client));
        } finally {
            $trava->release();
        }

        // A vigésima vaga continua disponível: a tentativa adiada não foi
        // cobrada, e o contador não andou.
        $this->assertTrue($budget->reserve($client));
    }

    public function test_a_consulta_por_posicao_com_a_trava_ocupada_e_adiada_e_nao_erro(): void
    {
        // Contenção e teto estourado chegam para quem chama como a mesma coisa:
        // adiar. A diferença é que a contenção é uma disputa interna do módulo,
        // nada que o serviço respondeu, e ela não pode virar erro inesperado no
        // meio de uma reconciliação.
        $client = $this->clientWithCertificate();
        $trava = Cache::lock('fiscal:consulta:'.$client->tax_id, 5);

        $this->assertTrue($trava->get(), 'O teste precisa segurar a trava que o orçamento usa.');

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Consulta pontual com a trava ocupada deveria ser adiada.');
        } catch (FiscalLookupDeferred) {
            // A mesma exceção do teto esgotado, e nenhuma requisição na rede.
        } finally {
            Http::assertNothingSent();
        }

        $trava->release();

        // O contador nem chegou a ser criado por uma consulta que não saiu.
        $this->assertTrue(resolve(FiscalLookupBudget::class)->reserve($client));
    }

    public function test_a_consulta_incremental_nao_gasta_o_orcamento_de_consultas(): void
    {
        // O teto é do fisco para consulta pontual, e a captura do dia a dia não
        // pode ser barrada por ele: um cliente com vinte lacunas conhecidas
        // continuaria sendo capturado normalmente.
        $client = $this->clientWithCertificate();

        $this->esgotaOrcamento($client);

        // Aqui a fixture é a do lote, e é a que cabe: `pull()` faz `distNSU` e o
        // serviço devolve o que pertence ao CNPJ a partir da posição pedida. A
        // fixture da `consNSU` é a do outro método.
        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertCount(1, $result->documents);
        Http::assertSentCount(1);
    }

    private function esgotaOrcamento(Client $client): void
    {
        $budget = resolve(FiscalLookupBudget::class);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $budget->reserve($client);
        }

        $this->assertFalse($budget->reserve($client), 'O teto deveria estar esgotado antes da consulta.');
    }

    /**
     * O corpo que o conector monta para o cliente — o mesmo `build()` do caminho
     * incremental, com a posição zero que a conversão espera encontrar.
     */
    private function envelopeFor(Client $client): string
    {
        $endpoint = config('fiscal.endpoints.nfe_distribuicao');

        return resolve(DfeSoapEnvelope::class)->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $endpoint['version'],
            cnpj: (string) $client->tax_id,
            cUf: '35',
            fromNsu: 0,
            method: $endpoint['method'],
            holder: $endpoint['holder'],
        );
    }

    private function connector(): NfeDistributionConnector
    {
        return resolve(NfeDistributionConnector::class);
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
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }
}
