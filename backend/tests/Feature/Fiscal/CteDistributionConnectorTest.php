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
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Cte\CteDistributionConnector;
use App\Services\Fiscal\Exceptions\FiscalClientStateUnknown;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Services\Fiscal\Support\ClientStateCode;
use App\Services\Fiscal\Support\DfePullReader;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DfeTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Conector de Distribution DF-e do CT-e.
 *
 * Aação SOAP, URL, namespace, versão e elemento que embrulha o payload estão em
 * `config/fiscal.php` e foram transcritos de um exemplo de terceiro testado em
 * produção — não de uma verificação feita deste checkout. O que este arquivo
 * fixa é a forma da requisição que eles produzem, e o canário de um único
 * cliente é o que confirma o resto.
 *
 * Nenhum teste aqui toca a rede: `preventStrayRequests` faz qualquer chamada
 * fora do `Http::fake()` explodir, e o `Storage::fake` impede que o
 * materializador leia o disco real.
 */
class CteDistributionConnectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ação SOAP do serviço, que é o que diz ao `.asmx` qual operação chamar.
     */
    private const SOAP_ACTION = 'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe/cteDistDFeInteresse';

    private const PRODUCAO = 'https://www1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx';

    private const HOMOLOGACAO = 'https://hom1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx';

    /**
     * Chave de acesso do `cteProc.xml` sintético, modelo `57` nos dois dígitos
     * que a chave carrega.
     */
    private const CHAVE_CTE = '35220799999999999999570010000001231123456786';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();

        // O orçamento mora no cache: um contador que sobrevive de um teste para o
        // outro transformaria o teto em número de teste.
        Cache::store('array')->clear();
    }

    public function test_a_fonte_e_a_distribuicao_de_cte(): void
    {
        $this->assertSame(FiscalSource::CteDistribuicao, $this->connector()->source());
    }

    /**
     * O contrato do lote íntegro, escrito de uma vez: a requisição que a
     * configuração transcrita produz, o documento parseado e a posição. É o
     * teste que fecha a entrada do CT-e na distribuição — se um namespace, uma
     * versão, um método ou o elemento que embrulha o payload mudar no caminho,
     * é aqui que muda.
     */
    public function test_o_lote_integro_entrega_documento_posicao_e_acao_soap_do_cte(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 200, 200, [
            $this->docZip(200, $this->fixture('cteProc.xml'), 'procCTe_v4.00.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(FiscalSource::CteDistribuicao, $this->connector()->source());

        $this->assertCount(1, $result->documents);

        $document = $result->documents[0];

        $this->assertSame(FiscalModel::Cte, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertSame(FiscalStage::Document, $document->stage);
        $this->assertSame(self::CHAVE_CTE, $document->chave);
        $this->assertSame('99999999999999', $document->emitenteCnpj);
        $this->assertSame('11222333000181', $document->destinatarioCnpj);
        $this->assertSame(200, $document->nsu);
        $this->assertSame('procCTe_v4.00.xsd', $document->schema);
        $this->assertFalse($document->mascarado);

        // A posição é a que a resposta devolveu, e o lote inteiro autoriza
        // adotá-la: `lastNsu` é 200 e não 201, e nada sobrou para recusar.
        $this->assertSame(200, $result->lastNsu);
        $this->assertSame(200, $result->maxNsu);
        $this->assertTrue($result->mayAdoptPosition);
        $this->assertSame([], $result->failures);
        $this->assertNull($result->blockedUntil);
        $this->assertNull($result->failure);
        $this->assertFalse($result->more);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::PRODUCAO, $request->url());

            $this->assertSame(
                'application/soap+xml; charset=utf-8; action="'.self::SOAP_ACTION.'"',
                $request->header('Content-Type')[0],
            );

            // O corpo é do CT-e: método, elemento que embrulha o payload,
            // namespace do serviço, namespace e versão do payload. Qualquer um
            // deles trocado pelo valor da NF-e é um corpo que o outro serviço
            // receberia — e o `.asmx` do CT-e não é o mesmo do NF-e.
            $this->assertStringContainsString('<cteDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe">', $request->body());
            $this->assertStringContainsString('<cteDadosMsg xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe">', $request->body());
            $this->assertStringContainsString('<distDFeInt xmlns="http://www.portalfiscal.inf.br/cte" versao="1.00">', $request->body());

            // E a posição pedida vai com quinze dígitos, sem nenhuma soma.
            $this->assertStringContainsString('<ultNSU>'.str_pad('0', 15, '0', STR_PAD_LEFT).'</ultNSU>', $request->body());
            $this->assertStringNotContainsString('<ultNSU>000000000000001</ultNSU>', $request->body());

            return true;
        });
    }

    /**
     * O conector de CT-e fala com o serviço de CT-e: nenhum valor do serviço de
     * NF-e pode aparecer no corpo, e o único teste que pega isso é o que olha
     * os dois serviços no mesmo lugar.
     */
    public function test_o_corpo_nao_carrega_nenhum_valor_do_servico_de_nfe(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(function (Request $request): bool {
            foreach (['NFeDistribuicaoDFe', 'nfeDistDFeInteresse', 'nfeDadosMsg', 'versao="1.01"', 'portalfiscal.inf.br/nfe'] as $daNfe) {
                $this->assertStringNotContainsString($daNfe, $request->body());
            }

            return true;
        });
    }

    public function test_a_requisicao_nao_carrega_assinatura_nem_cabecalho_soap(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        // A requisição não é assinada e não usa cabeçalho SOAP: a autenticação é
        // o certificado A1 do cliente no transporte, e o serviço rejeita
        // assinatura injetada com `cStat 215`.
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'Signature')
            && ! str_contains($request->body(), 'Header')
            && str_contains($request->body(), '<distDFeInt'));
    }

    public function test_nenhum_codigo_de_manifestacao_e_enviado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('CTeDistribuicaoDFe.asmx', $request->url());
            $this->assertStringNotContainsString('210200', $request->body());
            $this->assertStringNotContainsString('Manifestacao', $request->body());

            return true;
        });
    }

    public function test_a_url_segue_o_ambiente_configurado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::PRODUCAO);

        config(['fiscal.environment' => 'homologacao']);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::HOMOLOGACAO);
    }

    /**
     * Ambiente desconhecido cai em homologação, que é o lado que não produz
     * efeito legal — a mesma regra do transporte, e o mesmo motivo.
     */
    public function test_ambiente_desconhecido_consulta_a_homologacao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        config(['fiscal.environment' => 'sandbox']);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::HOMOLOGACAO);
    }

    public function test_a_posicao_viaja_com_quinze_digitos_e_nunca_e_incrementada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 200, 200, [
            $this->docZip(200, $this->fixture('cteProc.xml'), 'procCTe_v4.00.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 200, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<ultNSU>000000000000200</ultNSU>')
            && ! str_contains($request->body(), '<ultNSU>000000000000201</ultNSU>'));

        $this->assertSame(200, $result->lastNsu);
    }

    /**
     * A família do CT-e é o que o conector pergunta, e o que a chave de cada
     * documento é que responde: um lote traz os três modelos do mesmo serviço, e
     * um conector que recusasse os dois últimos por causa do primeiro estouraria
     * a captura do cliente no primeiro OS do lote.
     */
    public function test_o_lote_traz_os_tres_modelos_da_familia_do_cte(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(298, $this->fixture('cteProc.xml'), 'procCTe_v4.00.xsd'),
            $this->docZip(299, $this->fixture('cte-os.xml'), 'procCTeOS_v4.00.xsd'),
            $this->docZip(300, $this->fixture('cte-gtve.xml'), 'procGTVe_v4.00.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->failures);
        $this->assertSame(
            [FiscalModel::Cte, FiscalModel::CteOs, FiscalModel::Gtve],
            array_column($result->documents, 'model'),
        );
        $this->assertSame([298, 299, 300], array_column($result->documents, 'nsu'));
        $this->assertTrue($result->mayAdoptPosition);
    }

    /**
     * Uma raiz de CT-e que é conhecida e não é documento a indexar é pulada, e
     * a posição avança: recusá-la travaria o cliente no primeiro `inut` do lote,
     * para sempre, e não havia documento para perder naquela posição.
     */
    public function test_uma_entrada_que_nao_e_documento_e_pulada_e_a_posicao_avanca(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(299, $this->inut(), 'procInutCTe_v4.00.xsd'),
            $this->docZip(300, $this->fixture('cteProc.xml'), 'procCTe_v4.00.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->failures);
        $this->assertSame([300], array_column($result->documents, 'nsu'));
        $this->assertTrue($result->mayAdoptPosition);
    }

    /**
     * A mesma entrada, agora pela consulta por posição — a que a reconciliação
     * usa — e a resposta é `null`: a posição pedida não tem documento.
     *
     * O caminho de consulta por posição não tem onde levar uma recusa, porque a
     * assinatura devolve um documento ou `null`; e o que o lote traz aqui é uma
     * entrada que o coletor pula de propósito, sem registrá-la como recusa, para
     * não prender a posição. Um lote sem documento **e** sem recusa é o terceiro
     * estado, e sem um desfecho definido para ele o leitor buscaria a recusa que
     * não existe.
     */
    public function test_a_consulta_por_posicao_de_uma_entrada_que_nao_e_documento_volta_vazia(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(300, $this->inut(), 'procInutCTe_v4.00.xsd'),
        ]), 200)]);

        $this->assertNull($this->connector()->fetchByNsu($client, 300));
    }

    /**
     * Um `resNFe` entregue ao conector de CT-e é um documento real com etiqueta
     * errada: a chave é de outro documento e entraria na unicidade sem conflito
     * nenhum.
     */
    public function test_um_documento_de_outra_familia_e_recusado_sem_guardar(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(300, $this->fixture('resNFe.xml'), 'resNFe_v1.01.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->documents);
        $this->assertCount(1, $result->failures);
        $this->assertSame(300, $result->failures[0]->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $result->failures[0]->schema);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_pull_bloqueia_por_uma_hora_quando_nenhum_documento_e_localizado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->documents);
        $this->assertSame(FiscalFailure::NoDocuments, $result->failure);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        // A posição é o eco da que foi pedida, e por isso não é adotada.
        $this->assertSame(0, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_o_consumo_indevido_bloqueia_por_uma_hora_e_adota_a_posicao_do_corpo(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('656', 'Rejeicao: O CNPJ informado esta bloqueado para uso do servico de distribuicao', 1678), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(FiscalFailure::Blocked, $result->failure);
        $this->assertSame(1678, $result->lastNsu);
        $this->assertTrue($result->mayAdoptPosition);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
    }

    public function test_uma_rejeicao_que_nao_e_documento_vira_excecao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('593', 'Rejeicao: Certificado emissor invalido', 0), 200)]);

        try {
            $this->connector()->pull($client, 0, 50);

            $this->fail('Uma rejeição do serviço deveria virar FiscalException.');
        } catch (FiscalException $exception) {
            $this->assertSame(FiscalFailure::Unauthorized, $exception->failure);
            $this->assertSame('Rejeicao: Certificado emissor invalido', $exception->getMessage());
        }
    }

    public function test_usa_o_cnpj_e_a_uf_do_proprio_cliente(): void
    {
        $client = $this->clientWithCertificate('12345678000199');

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<CNPJ>12345678000199</CNPJ>')
            && str_contains($request->body(), '<cUFAutor>35</cUFAutor>'));
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

        $this->assertSame(config('fiscal.ca_bundle'), $guzzle['verify']);
        $this->assertFileExists($guzzle['verify']);
    }

    public function test_cliente_sem_certificado_recusa_antes_de_qualquer_chamada(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->expectException(FiscalRequestNotSent::class);
        $this->expectExceptionMessage('Cliente sem certificado A1 vigente.');

        try {
            $this->connector()->pull($client, 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_um_cnpj_que_o_schema_recusa_nunca_sai_para_a_rede(): void
    {
        // CNPJ com 13 dígitos: o `TCnpj` do XSD exige catorze. O corpo é
        // conferido localmente antes de qualquer byte na rede.
        $client = $this->clientWithCertificate('1234567800019');

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requisição rejeitada pelo schema');

        try {
            $this->connector()->pull($client, 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * O serviço de distribuição de CT-e não oferece consulta por chave de
     * acesso: a recusa é antes de qualquer requisição, e não uma resposta do
     * fisco que alguém transformou em exceção depois de uma ida à rede.
     */
    public function test_a_consulta_por_chave_e_recusada_sem_chamar_o_servico(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('consulta por chave de acesso');

        try {
            $this->connector()->fetchByChave($client, self::CHAVE_CTE);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_consulta_por_posicao_vai_com_consnsu_e_traz_o_documento(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 100, 100, [
            $this->docZip(100, $this->fixture('cteProc.xml'), 'procCTe_v4.00.xsd'),
        ]), 200)]);

        $document = $this->connector()->fetchByNsu($client, 100);

        $this->assertNotNull($document);
        $this->assertSame(self::CHAVE_CTE, $document->chave);
        $this->assertSame(100, $document->nsu);

        // Consulta por posição e consulta incremental não podem se confundir: o
        // corpo vai com `consNSU` e sem nenhum `distNSU`.
        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('<consNSU><NSU>000000000000100</NSU></consNSU>', $request->body());
            $this->assertStringNotContainsString('distNSU', $request->body());

            return true;
        });
    }

    public function test_a_consulta_por_posicao_devolve_nulo_quando_o_servico_nao_localiza(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->assertNull($this->connector()->fetchByNsu($client, 100));
    }

    public function test_a_consulta_por_posicao_nao_chama_ausencia_quando_o_cnpj_esta_bloqueado(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('656', 'Rejeicao: O CNPJ informado esta bloqueado', 1678), 200)]);

        // `null` aqui diria "esta posição está vazia" e mandaria a reconciliação
        // marcar a lacuna como resolvida, com o CNPJ bloqueado pelo fisco.
        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Um consumo indevido deveria virar FiscalException, não ausência de documento.');
        } catch (FiscalException $exception) {
            $this->assertSame(FiscalFailure::Blocked, $exception->failure);
        }
    }

    /**
     * A consulta por posição de CT-e gasta o mesmo teto por CNPJ que a de
     * NF-e: é o mesmo fisco contando consulta pontual do mesmo cliente, e um
     * teto só para um dos dois serviço deixaria a outra metade do consumo
     * livre.
     */
    public function test_a_consulta_por_posicao_gasta_o_orcamento_horario_de_consultas(): void
    {
        $client = $this->clientWithCertificate();
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 20; $tentativa++) {
            $budget->reserve($client);
        }

        $this->assertFalse($budget->reserve($client), 'O teto deveria estar esgotado antes da consulta.');

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Consulta pontual sem reserva deveria ser adiada.');
        } catch (FiscalLookupDeferred $exception) {
            $this->assertSame('Limite horário de consultas pontuais atingido.', $exception->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_consulta_incremental_nao_gasta_o_orcamento_de_consultas(): void
    {
        $client = $this->clientWithCertificate();

        for ($tentativa = 1; $tentativa <= 20; $tentativa++) {
            resolve(FiscalLookupBudget::class)->reserve($client);
        }

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSentCount(1);
    }

    /**
     * O conector resolve pela fonte, e a fonte de CT-e só pode dar o conector de
     * CT-e. Um registro que mandasse CT-e para o conector de NF-e faria a
     * consulta de um serviço pelo endpoint do outro e gravaria a resposta na
     * fonte errada — a linha pior possível na tabela de documentos.
     */
    public function test_o_registro_da_a_cada_fonte_o_seu_conector(): void
    {
        $registry = resolve(FiscalConnectorRegistry::class);

        $this->assertInstanceOf(CteDistributionConnector::class, $registry->for(FiscalSource::CteDistribuicao));
        $this->assertInstanceOf(NfeDistributionConnector::class, $registry->for(FiscalSource::NfeDistribuicao));
        $this->assertSame(FiscalSource::CteDistribuicao, $registry->for(FiscalSource::CteDistribuicao)->source());
        $this->assertNotInstanceOf(NfeDistributionConnector::class, $registry->for(FiscalSource::CteDistribuicao));
    }

    /**
     * O contrato `FiscalConnector` não resolve para nada de propósito: com o
     * registro, todo caminho que fala com o fisco resolve o conector pela fonte.
     * Uma ligação da interface para o conector de NF-e seria a armadilha — um
     * código que tipa a interface e pergunta `source()` receberia NF-e, e
     * ninguém notaria até um documento de CT-e ser gravado na fonte da NF-e.
     */
    public function test_o_contrato_do_conector_nao_resolve_para_nenhum_conector(): void
    {
        $this->assertFalse(app()->bound(FiscalConnector::class));
    }

    public function test_um_registro_sem_a_fonte_recusa_nomeando_a_fonte(): void
    {
        // A recusa é o que impede o caminho errado de existir, e é o mesmo
        // "não tem conector" que o comando e a reconciliação já diziam — só que
        // agora a resposta vem de um lugar só, e nomeia a fonte.
        $registry = new FiscalConnectorRegistry([
            FiscalSource::NfeDistribuicao->value => NfeDistributionConnector::class,
        ]);

        $this->assertTrue($registry->has(FiscalSource::NfeDistribuicao));
        $this->assertFalse($registry->has(FiscalSource::CteDistribuicao));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cte_distribuicao');

        $registry->for(FiscalSource::CteDistribuicao);
    }

    public function test_uf_desconhecida_recusa_antes_de_qualquer_chamada(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'XX',
        ]);

        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        // `cUFAutor` é a UF do interessado e o serviço aceita qualquer código válido
        // da tabela do IBGE: um valor inventado aqui seria uma afirmação falsa
        // sobre quem pergunta, e nada voltaria para denunciá-la.
        $this->expectException(FiscalClientStateUnknown::class);
        $this->expectExceptionMessage('não está na tabela de UFs');

        try {
            $this->connector()->pull($client->refresh(), 0, 50);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * A ordem entre as duas guardas da consulta por posição: uma consulta que
     * não chegou a existir não pode ser cobrada como consulta que saiu, e é a
     * vaga do teto que se cobra aqui. Sem esta ordem, apagar uma linha resolveria
     * o problema do certificado e debitaria o orçamento do CNPJ por uma
     * requisição que nunca saiu.
     */
    public function test_a_consulta_por_posicao_sem_certificado_nao_gasta_a_vaga_do_orcamento(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        // Dezenove vagas: sobra exatamente uma, e ela é a que a consulta recusada
        // não pode gastar. Um `reserve()` verdadeiro depois da recusa é o que
        // prova que a vaga está intacta — com a ordem invertida, a recusa teria
        // consumido a última e o `reserve()` seguinte devolveria falso.
        $budget = resolve(FiscalLookupBudget::class);

        for ($tentativa = 1; $tentativa <= 19; $tentativa++) {
            $budget->reserve($client);
        }

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        try {
            $this->connector()->fetchByNsu($client, 100);

            $this->fail('Cliente sem certificado deveria ser recusado antes da consulta.');
        } catch (FiscalRequestNotSent) {
            // esperado: a pre-flight do transporte
        } finally {
            Http::assertNothingSent();
        }

        // Nada saiu para a rede, e nada foi debitado do teto do CNPJ.
        $this->assertTrue($budget->reserve($client));
        $this->assertFalse($budget->reserve($client));
    }

    private function connector(): CteDistributionConnector
    {
        return new CteDistributionConnector(
            resolve(DfeSoapEnvelope::class),
            resolve(DfeTransport::class),
            resolve(DfePullReader::class),
            resolve(ClientStateCode::class),
            resolve(FiscalLookupBudget::class),
        );
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

    /**
     * A resposta do serviço de CT-e com a mesma forma da de NF-e: o `retDistDFeInt`
     * é o mesmo elemento com o namespace e a versão do CT-e, e é ele que o
     * parser encontra por nome local.
     */
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
            .'<cteDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe">'
            .'<cteDistDFeInteresseResult>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/cte" versao="1.00">'
            ."<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            .'<ultNSU>'.str_pad((string) $ultNsu, 15, '0', STR_PAD_LEFT).'</ultNSU>'
            .'<maxNSU>'.str_pad((string) $maxNsu, 15, '0', STR_PAD_LEFT).'</maxNSU>'
            .$lote
            .'</retDistDFeInt></cteDistDFeInteresseResult>'
            .'</cteDistDFeInteresseResponse></soap:Body></soap:Envelope>';
    }

    private function docZip(int $nsu, string $document, string $schema = 'procCTe_v4.00.xsd'): string
    {
        return '<docZip NSU="'.str_pad((string) $nsu, 15, '0', STR_PAD_LEFT).'" schema="'.$schema.'">'
            .base64_encode(gzencode($document))
            .'</docZip>';
    }

    /**
     * Inutilização de CT-e, com o corpo mínimo que a entrada precisa. O que
     * separa uma entrada que não é documento de uma que recusa é a **raiz**, e
     * nenhum campo do corpo é lido — o serviço é que não pediu nada sobre a
     * posição, e a recusa viria do parsing de algo que ninguém leu.
     *
     * O corpo é modelado; a raiz é a que o catálogo de raízes nomeia, e é a que
     * o `FiscalXmlMetadata` conhece. A forma real do `procInutCTe` não muda nada
     * aqui, e nenhum campo dele entra no resultado.
     */
    private function inut(): string
    {
        return '<procInutCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<infInut><cnpj>99999999999999</cnpj></infInut>'
            .'</procInutCTe>';
    }
}
