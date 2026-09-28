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
use App\Services\Fiscal\Exceptions\FiscalClientStateUnknown;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Services\Fiscal\Support\ClientStateCode;
use App\Services\Fiscal\Support\DfePullReader;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DfeTransport;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\Fiscal\Support\XmlQuery;
use DOMDocument;
use DOMXPath;
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

    /**
     * O conector de NF-e é o que o registro dá para a fonte de NF-e, e a fonte
     * é o único caminho: com dois serviços de distribuição, um código que pegasse
     * "o conector" em vez de "o conector desta fonte" falaria com CT-e no
     * `.asmx` da NF-e, sem nada reclamar.
     */
    public function test_o_registro_resolve_o_conector_de_nfe_para_a_fonte_de_nfe(): void
    {
        $registry = resolve(FiscalConnectorRegistry::class);

        $this->assertInstanceOf(NfeDistributionConnector::class, $registry->for(FiscalSource::NfeDistribuicao));
        $this->assertSame(FiscalSource::NfeDistribuicao, $registry->for(FiscalSource::NfeDistribuicao)->source());
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
        $this->assertSame(FiscalStage::Summary, $document->stage);
        $this->assertSame(self::CHAVE, $document->chave);
        $this->assertSame('', $document->eventId);
        $this->assertSame('99999999999999', $document->emitenteCnpj);
        $this->assertNull($document->destinatarioCnpj);
        $this->assertSame('710.00', $document->valorTotal);
        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $document->digVal);
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

    /**
     * O contrato do lote íntegro, escrito de uma vez: a fonte, o documento
     * parseado, a posição e a requisição. É o teste que fecha a extração da
     * mecânica compartilhada — se um campo, uma posição ou um cabeçalho mudar
     * no caminho, é aqui que muda.
     */
    public function test_o_lote_integro_entrega_documento_posicao_e_acao_soap_da_nfe(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(FiscalSource::NfeDistribuicao, $this->connector()->source());

        $this->assertCount(1, $result->documents);

        $document = $result->documents[0];

        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertSame(FiscalStage::Summary, $document->stage);
        $this->assertSame(self::CHAVE, $document->chave);
        $this->assertSame('', $document->eventId);
        $this->assertSame('99999999999999', $document->emitenteCnpj);
        $this->assertNull($document->destinatarioCnpj);
        $this->assertSame('710.00', $document->valorTotal);
        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $document->digVal);
        $this->assertSame(200, $document->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $document->schema);
        $this->assertSame('2022-04-04T11:54:49-03:00', $document->emissaoAt?->toIso8601String());
        $this->assertNull($document->eventoOcorridoEmAt);
        $this->assertStringContainsString('<chNFe>'.self::CHAVE.'</chNFe>', $document->xml);

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
            // A ação SOAP viaja no `Content-Type`, e é ela que diz a qual
            // serviço a consulta foi dirigida.
            $this->assertSame(
                'application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse"',
                $request->header('Content-Type')[0]
            );

            // E a posição pedida vai com quinze dígitos, sem nenhuma soma.
            $this->assertStringContainsString('<ultNSU>'.str_pad('0', 15, '0', STR_PAD_LEFT).'</ultNSU>', $request->body());
            $this->assertStringNotContainsString('<ultNSU>000000000000001</ultNSU>', $request->body());

            return true;
        });
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

    public function test_a_posicao_do_lote_integro_pode_ser_adotada(): void
    {
        // "Avanço do cursor": um lote que virou documento inteiro autoriza a
        // posição que a resposta devolveu.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertTrue($result->mayAdoptPosition);
        $this->assertSame(200, $result->lastNsu);
    }

    public function test_a_posicao_de_um_lote_sem_documentos_nao_pode_ser_adotada(): void
    {
        // "Resposta sem documentos": a posição armazenada fica intacta. O que o
        // serviço devolve nesse caso é o eco da posição pedida, não uma
        // confirmação — gravá-la sobrescreveria o cursor com o valor anterior e
        // apagaria a posição que a consulta anterior tinha conquistado.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_a_posicao_do_consumo_indevido_pode_ser_adotada(): void
    {
        // "Rejeição por consumo indevido": a posição relatada é gravada, porque
        // é a alavanca de recuperação que o fisco oferece dentro da rejeição.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_656_com_nsu.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertTrue($result->mayAdoptPosition);
        $this->assertSame(1678, $result->lastNsu);
    }

    public function test_a_parada_de_uma_hora_sem_documento_e_rotulada_como_frio_do_fisco(): void
    {
        // Duas paradas de uma hora que o painel precisa distinguir: esta é o
        // fisco sem nada novo para entregar, e a outra é o CNPJ consultando
        // demais. O rótulo que o conector carrega é o que separa as duas lá
        // dentro, porque a pausa em si é idêntica.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_137.xml'), 200)]);

        $result = $this->connector()->pull($client, 900, 50);

        $this->assertSame(FiscalFailure::NoDocuments, $result->failure);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        // A posição é o eco da que foi pedida, e por isso não é adotada.
        $this->assertSame(900, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_o_consumo_indevido_e_rotulado_para_o_operador(): void
    {
        // A outra metade do mesmo par: mesmo `cStat` de espera, outra
        // gravidade, e a posição do corpo da rejeição continua sendo a
        // alavanca de recuperação.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_656_com_nsu.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(FiscalFailure::Blocked, $result->failure);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        $this->assertTrue($result->mayAdoptPosition);
        $this->assertSame(1678, $result->lastNsu);
    }

    public function test_o_lote_que_veio_nao_caria_rotulo_de_parada(): void
    {
        // O rótulo descreve uma pausa que o serviço mandou, e `138` é o
        // serviço entregando: sem ele, um lote normal apareceria para quem lê
        // como se tivesse parado.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertNull($result->failure);
        $this->assertNull($result->blockedUntil);
    }

    public function test_uma_entrada_corrompida_e_registrada_e_o_lote_continua(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(298, $this->resNFe()),
            // Base64 que não é nenhum dos três containers aceitos: nem ZIP, nem
            // gZip, nem zlib.
            $this->docZipPayload(299, base64_encode('isto nao e um container comprimido')),
            $this->docZip(300, $this->resNFe()),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        // A entrada do meio não cancela a de depois: o lote inteiro é lido.
        $this->assertSame([298, 300], array_column($result->documents, 'nsu'));
        $this->assertCount(1, $result->failures);
        $this->assertSame(299, $result->failures[0]->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $result->failures[0]->schema);
        // O motivo nomeia a etapa e não carrega o payload: o Global Constraint
        // de não registrar o `docZip` continua valendo aqui.
        $this->assertSame('DocZipDecoder não decodificou o payload comprimido.', $result->failures[0]->reason);
        $this->assertStringNotContainsString('container comprimido', $result->failures[0]->reason);
        // E a posição não é adotada: avançar além da entrada que falhou
        // perderia documento em silêncio na próxima consulta.
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_uma_chave_com_dv_invalido_e_registrada_sem_guardar_o_documento(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(300, $this->resNFe('35220499999999999999550010020000001240556603')),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        // "Chave com dígito verificador inválido": rejeita, não guarda nada e
        // reporta. O motivo não repete a chave — a mensagem da exceção de
        // metadados a interpola, e o motivo vai para o painel e para o log.
        $this->assertSame([], $result->documents);
        $this->assertCount(1, $result->failures);
        $this->assertSame(300, $result->failures[0]->nsu);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $result->failures[0]->reason);
        $this->assertStringNotContainsString('35220499999999999999550010020000001240556603', $result->failures[0]->reason);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_um_documento_de_outro_modelo_e_registrado_sem_virar_nfe(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            // Um `resCTe` entregue ao conector da NF-e é um documento real com
            // etiqueta errada: a chave é de outro documento e entraria na
            // unicidade sem conflito nenhum.
            $this->docZip(300, $this->resCTe(), 'resCTe_v1.00.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->documents);
        $this->assertCount(1, $result->failures);
        $this->assertSame('resCTe_v1.00.xsd', $result->failures[0]->schema);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $result->failures[0]->reason);
    }

    public function test_uma_nfe_com_raiz_fora_do_catalogo_ainda_e_capturada_e_a_posicao_avanca(): void
    {
        // A mesma distinção do teste unitário, vista pelo conector que está em
        // produção. Uma raiz que o catálogo de raízes não conhece **não** pode
        // virar recusa aqui: `mayAdoptPosition` ficaria falso para sempre, a
        // mesma posição voltaria em toda consulta e o cliente nunca avançaria.
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(300, $this->entregaDeDocumento(), 'resNFe_v1.01.xsd'),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->failures);
        $this->assertCount(1, $result->documents);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->documents[0]->chave);
        $this->assertSame(300, $result->documents[0]->nsu);
        $this->assertTrue($result->mayAdoptPosition);
    }

    /**
     * O contrato do lote com recusa: a entrada que não vira documento é
     * registrada com a frase da etapa que a recusou, a posição não é adotada e
     * a posição devolvida continua sendo a da resposta. É a segunda metade do
     * contrato acima, e a que a extração da coleta de entradas não pode mudar
     * em silêncio.
     */
    public function test_uma_entrada_que_nao_vira_documento_e_registrada_sem_adotar_a_posicao(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 300, 300, [
            $this->docZip(298, $this->resNFe()),
            // Base64 que não é nenhum dos três containers aceitos: nem ZIP, nem
            // gZip, nem zlib.
            $this->docZipPayload(299, base64_encode('isto nao e um container comprimido')),
            $this->docZip(300, $this->resNFe()),
            // Chave com dígito verificador inválido: o payload abre, e é a
            // extração de metadados que recusa.
            $this->docZip(301, $this->resNFe('35220499999999999999550010020000001240556603')),
        ]), 200)]);

        $result = $this->connector()->pull($client, 0, 50);

        // As duas entradas boas em volta das duas recusadas continuam legíveis:
        // uma posição ilegível é um buraco a reconciliar, não o fim da fila.
        $this->assertSame([298, 300], array_column($result->documents, 'nsu'));

        // Cada recusa nomeia a etapa que recusou, e a frase é a mesma em
        // qualquer conector: é o que o painel mostra e o que o log registra.
        $this->assertCount(2, $result->failures);
        $this->assertSame([299, 301], array_column($result->failures, 'nsu'));
        $this->assertSame('resNFe_v1.01.xsd', $result->failures[0]->schema);
        $this->assertSame('DocZipDecoder não decodificou o payload comprimido.', $result->failures[0]->reason);
        $this->assertSame('resNFe_v1.01.xsd', $result->failures[1]->schema);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $result->failures[1]->reason);

        // A posição devolvida é a da resposta, e ela não é adotada: avançar
        // depois de uma entrada recusada perderia documento em silêncio na
        // consulta seguinte.
        $this->assertSame(300, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
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
        $this->expectException(FiscalClientStateUnknown::class);
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

        $this->expectException(FiscalRequestNotSent::class);
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

        $this->expectException(FiscalRequestNotSent::class);
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
        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('<consChNFe><chNFe>'.self::CHAVE.'</chNFe></consChNFe>', $request->body());
            $this->assertStringNotContainsString('distNSU', $request->body());

            // E o corpo que entrou no serviço é um que o schema local aceita:
            // o `consChNFe` é uma das opções do grupo de consulta do
            // `distDFeInt`, e o mesmo `validate()` do caminho por posição roda
            // sobre ele.
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

    public function test_a_consulta_por_chave_e_recusada_pelo_schema_antes_de_sair(): void
    {
        // CNPJ com 13 dígitos: o `TCnpj` do XSD exige 14. O corpo da consulta
        // por chave é o único que sai de uma reescrita de outro corpo, então
        // ele passa pelo mesmo validador antes de qualquer byte na rede.
        $client = $this->clientWithCertificate('1234567800019');

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138.xml'), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requisição rejeitada pelo schema');

        try {
            $this->connector()->fetchByChave($client, self::CHAVE);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * `null` significa uma coisa só: **o serviço diz que não tem aquele
     * documento.**
     */
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

    public function test_fetch_by_chave_nao_chama_ausencia_quando_a_resposta_nao_deixa_ler_nada(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('138', 'Documento(s) localizado(s)', 200, 200, [
            $this->docZipPayload(200, base64_encode('isto nao e um container comprimido')),
        ]), 200)]);

        // O serviço disse que localizei, e o que veio não pôde ser lido.
        // Devolver `null` aqui seria dizer que a chave não existe com ele.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DocZipDecoder não decodificou o payload comprimido.');

        $this->connector()->fetchByChave($client, self::CHAVE);
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

    public function test_um_fault_soap_em_200_e_falha_retentavel(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->fault('Servico em manutencao programada.'), 200)]);

        // Um fault chega no mesmo `2xx` e no mesmo envelope, e o parser o leria
        // como "não contém retDistDFeInt" — uma condição esperada do serviço
        // classificada como se fosse defeito nosso.
        $exception = $this->falhaEm($client);

        $this->assertSame(FiscalFailure::Upstream, $exception->failure);
        $this->assertTrue($exception->failure->retryable());
        $this->assertFalse($exception->failure->blocksForAnHour());
        // O texto do fault é a condição, e ela não se perde.
        $this->assertStringContainsString('Servico em manutencao programada.', $exception->getMessage());
        // O `detail` fica de fora: é onde um serviço ecoa o pedido, e o corpo
        // não é o que se registra.
        $this->assertStringNotContainsString('CONTEUDO-DO-DETAIL', $exception->getMessage());
    }

    public function test_o_fault_e_retentavel_e_a_rejeicao_de_schema_nao_e(): void
    {
        $client = $this->clientWithCertificate();

        // Duas respostas no mesmo teste exigem uma sequência: um segundo
        // `Http::fake()` acumularia stub, e o primeiro registrado é quem
        // responde.
        Http::fake(['*' => Http::sequence()
            ->push($this->fault('Servico fora do ar.'), 200)
            ->push($this->responseWith('215', 'Rejeicao: Falha no Schema XML', 0), 200)]);

        // O fisco está recusando de processar, não recusando o pedido: amanhã
        // a mesma consulta funciona.
        $falha = $this->falhaEm($client);

        $this->assertSame(FiscalFailure::Upstream, $falha->failure);
        $this->assertTrue($falha->failure->retryable());

        // A rejeição de schema é erro nosso, e repetir devolve a mesma resposta
        // amanhã. As duas direções precisam ser distinguíveis por quem decide
        // se tenta de novo.
        $rejeicao = $this->falhaEm($client);

        $this->assertSame(FiscalFailure::Rejected, $rejeicao->failure);
        $this->assertFalse($rejeicao->failure->retryable());
    }

    public function test_um_fault_de_soap_12_tambem_e_lido(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->faultSoap12('Codigo da operacao cancelado pelo servidor.'), 200)]);

        $exception = $this->falhaEm($client);

        $this->assertSame(FiscalFailure::Upstream, $exception->failure);
        $this->assertStringContainsString('Codigo da operacao cancelado pelo servidor.', $exception->getMessage());
    }

    public function test_o_texto_do_fault_e_limitado_antes_de_virar_mensagem(): void
    {
        $client = $this->clientWithCertificate();

        // Um `faultstring` de serviço pode ser longo, e a mensagem de exceção vai
        // para o painel e para o log: o limite é o que impede que o tamanho da
        // resposta do fisco vire o tamanho do nosso registro.
        Http::fake(['*' => Http::response($this->fault(str_repeat('detalhe-longo-', 200)), 200)]);

        $exception = $this->falhaEm($client);

        $this->assertLessThanOrEqual(400, mb_strlen($exception->getMessage()));
        $this->assertStringContainsString('detalhe-longo-', $exception->getMessage());
        $this->assertStringEndsWith('…', $exception->getMessage());
    }

    public function test_um_200_sem_fault_e_sem_resultado_continua_sendo_erro_do_parser(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response('<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><algoImprevisto xmlns="http://exemplo.invalido"/></soap:Body></soap:Envelope>', 200)]);

        // Não é fault, então o caso do parser continua significando o que
        // significava: um `2xx` cujo corpo não é a resposta do serviço.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A resposta do serviço não contém retDistDFeInt.');

        $this->connector()->pull($client, 0, 50);
    }

    private function fault(string $text): string
    {
        // O endpoint é `.asmx`, que é .NET, e o fault que um ASMX devolve é o de
        // SOAP 1.1, com `faultstring` — mesmo numa requisição 1.2.
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
            .'<faultcode>soap:Server</faultcode>'
            .'<faultstring>'.$text.'</faultstring>'
            .'<detail>CONTEUDO-DO-DETAIL</detail>'
            .'</soap:Fault></soap:Body></soap:Envelope>';
    }

    private function faultSoap12(string $text): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><soap:Fault>'
            .'<soap:Code><soap:Value>soap:Sender</soap:Value></soap:Code>'
            .'<soap:Reason><soap:Text xml:lang="pt-BR">'.$text.'</soap:Text></soap:Reason>'
            .'</soap:Fault></soap:Body></soap:Envelope>';
    }

    /**
     * Um `Http::fake()` por teste: o registro de stubs acumula a cada chamada,
     * e o primeiro registrado é quem responde.
     */
    private function pullComRejeicao(string $cStat): FiscalException
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith($cStat, 'Rejeicao do teste', 0), 200)]);

        $exception = $this->falhaEm($client);

        $this->assertSame('Rejeicao do teste', $exception->getMessage());

        return $exception;
    }

    private function falhaEm(Client $client): FiscalException
    {
        try {
            $this->connector()->pull($client, 0, 50);
        } catch (FiscalException $exception) {
            return $exception;
        }

        $this->fail('A resposta deveria ter virado FiscalException.');
    }

    private function connector(): NfeDistributionConnector
    {
        return new NfeDistributionConnector(
            resolve(DfeSoapEnvelope::class),
            resolve(DfeTransport::class),
            resolve(DfePullReader::class),
            resolve(ClientStateCode::class),
            resolve(FiscalLookupBudget::class),
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
        return $this->docZip($nsu, $this->resNFe());
    }

    private function docZip(int $nsu, string $document, string $schema = 'resNFe_v1.01.xsd'): string
    {
        return $this->docZipPayload($nsu, base64_encode(gzencode($document)), $schema);
    }

    private function docZipPayload(int $nsu, string $payload, string $schema = 'resNFe_v1.01.xsd'): string
    {
        return '<docZip NSU="'.str_pad((string) $nsu, 15, '0', STR_PAD_LEFT).'" schema="'.$schema.'">'
            .$payload
            .'</docZip>';
    }

    private function resNFe(string $chave = self::CHAVE): string
    {
        return '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<chNFe>'.$chave.'</chNFe>'
            .'<CNPJ>99999999999999</CNPJ>'
            .'<dhEmi>2022-04-04T11:54:49-03:00</dhEmi>'
            .'<vNF>710.00</vNF>'
            .'</resNFe>';
    }

    /**
     * Raiz de NF-e fora do catálogo de raízes, com a chave legível no topo. O
     * payload é o mesmo `resNFe` com outro nome de elemento — o que um serviço
     * que evolui o leiaute entrega sem aviso, e o que este checkout não tem como
     * enumerar.
     */
    private function entregaDeDocumento(): string
    {
        return '<entregaDeDocumento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<chNFe>'.self::CHAVE.'</chNFe>'
            .'<CNPJ>99999999999999</CNPJ>'
            .'<dhEmi>2022-04-04T11:54:49-03:00</dhEmi>'
            .'<vNF>710.00</vNF>'
            .'</entregaDeDocumento>';
    }

    /**
     * Chave de CT-e com DV válido: o que a rejeita não é o dígito, é o modelo
     * `57` nos dois dígitos que a chave carrega.
     */
    private function resCTe(): string
    {
        return '<resCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="1.00">'
            .'<chCTe>35200499999999999999570010010000001231000000</chCTe>'
            .'<CNPJ>99999999999999</CNPJ>'
            .'<dhRecbto>2022-04-04T11:54:49-03:00</dhRecbto>'
            .'</resCTe>';
    }
}
