<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Capture\FiscalXmlPath;
use App\Services\Fiscal\Contracts\PulledDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * O writer: o único caminho de escrita do módulo.
 *
 * O que estes testes fixam é a identidade — as três etapas da distribuição de um
 * documento convivem, reprocessar a mesma etapa sobrescreve e não duplica — e a
 * ordem dentro de `store()`: o arquivo antes da linha.
 */
class FiscalDocumentWriterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chave de acesso com o dígito verificador que o módulo 11 da NT exige
     * (soma 980, resto 1) — a mesma dos fixtures de distribuição. A posição da
     * entrega é o `$nsu`; a chave é a identidade do documento, e as duas coisas
     * não têm relação nenhuma.
     */
    private const CHAVE = '35220499999999999999550010020000001240556600';

    /**
     * SHA-1 de 20 bytes em base64, que é sempre 28 caracteres — o que a coluna
     * `varchar(28)` aceita. O valor de 32 caracteres que estava aqui antes
     * passava no sqlite, onde o comprimento do `varchar` não é aplicado, e
     * rebentava no Postgres com *value too long*: o teste nunca exercitou o
     * digest contra a coluna que o guarda.
     */
    private const DIGVAL = 'i2rqNaD6rqmCfhXHyTBf4xe1ImQ=';

    /**
     * Outro SHA-1 de 20 bytes, para o caso divergente.
     */
    private const OUTRO_DIGVAL = 'uZskzthn678Zkeu/V/kJkmfqywY=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
    }

    public function test_stores_the_document_with_its_xml_on_the_private_disk(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));

        $this->assertSame($account->getKey(), $document->account_id);
        $this->assertSame($client->getKey(), $document->client_id);
        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertSame(FiscalStage::Document, $document->stage);
        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));
        $this->assertSame(hash('sha256', '<resNFe/>'), $document->sha256);
        $this->assertSame(9, $document->xml_bytes);
    }

    public function test_stores_the_path_the_shared_contract_derives(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));

        // A coluna é lida de volta por quem serve o download, então o caminho
        // gravado tem de ser o de `FiscalXmlPath::for()` — o mesmo que a factory
        // deriva, e não uma segunda conta feita dentro do writer.
        $this->assertSame(
            FiscalXmlPath::for((int) $account->getKey(), (int) $client->getKey(), self::CHAVE, '', FiscalStage::Document),
            $document->storage_path,
        );
    }

    public function test_reprocessing_the_same_document_overwrites_instead_of_duplicating(): void
    {
        [, $client] = $this->tenant();

        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));
        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));

        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_reprocessing_replaces_the_payload_of_the_same_row(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $first = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));
        $second = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(260, xml: '<nfeProc/>'));

        // Mesma linha, conteúdo novo: sobrescrever é gravar por cima, e o
        // arquivo em disco é o da última entrega, com o hash e o tamanho
        // conferindo com os bytes que ele aponta.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(260, $second->nsu);
        $this->assertSame('<nfeProc/>', Storage::disk('fiscal')->get($second->storage_path));
        $this->assertSame(hash('sha256', '<nfeProc/>'), $second->sha256);
        $this->assertSame(10, $second->xml_bytes);
    }

    public function test_the_summary_and_the_full_document_are_two_records_of_one_document(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary));
        $completo = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document));

        // A spec exige duas linhas: resumo e documento completo são duas
        // entregas de distribuição do mesmo documento, e as duas continuam
        // recuperáveis pela chave de acesso. Com `event_id` vazio nas duas e sem
        // a etapa na chave, o documento completo apagava o XML, a posição e o
        // digest do resumo.
        $this->assertSame(2, FiscalDocument::count());
        $this->assertSame(FiscalKind::Document, $completo->kind);
        $this->assertSame('', $completo->event_id);
        $this->assertSame(FiscalStage::Document, $completo->stage);

        $resumo = FiscalDocument::query()->where('stage', FiscalStage::Summary->value)->firstOrFail();

        // Cada etapa tem o seu XML, na sua posição, no seu arquivo.
        $this->assertSame(200, $resumo->nsu);
        $this->assertSame(250, $completo->nsu);
        $this->assertNotSame($resumo->storage_path, $completo->storage_path);
        $this->assertSame('<resNFe/>', Storage::disk('fiscal')->get($resumo->storage_path));
        $this->assertSame('<nfeProc/>', Storage::disk('fiscal')->get($completo->storage_path));
    }

    public function test_the_three_stages_of_one_document_coexist(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event));

        // Três registros de distribuição, um documento — que é o que a decisão 4
        // promete e o que a chave composta só entregava depois de incluir a etapa.
        $this->assertSame(3, FiscalDocument::count());
        $this->assertSame(
            [FiscalStage::Summary, FiscalStage::Document, FiscalStage::Event],
            FiscalDocument::query()->orderBy('nsu')->pluck('stage')->all(),
        );
    }

    public function test_reprocessing_a_stage_overwrites_it_and_not_its_counterpart(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document));

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary));

        $this->assertSame(2, FiscalDocument::count());
        $this->assertSame(200, FiscalDocument::query()->where('stage', FiscalStage::Summary->value)->firstOrFail()->nsu);
    }

    public function test_event_is_stored_alongside_its_document(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $documento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Document));
        $evento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event));

        $this->assertSame(2, FiscalDocument::count());

        // Mesma chave, mesmo arquivo? Não: a etapa e o `event_id` é o que separa
        // as entregas da distribuição, e um evento que sobrescrevesse o XML da
        // nota seria um documento que baixa o arquivo de outro.
        $this->assertNotSame($documento->storage_path, $evento->storage_path);
        $this->assertTrue(Storage::disk('fiscal')->exists($documento->storage_path));
        $this->assertTrue(Storage::disk('fiscal')->exists($evento->storage_path));
        $this->assertSame('<resNFe/>', Storage::disk('fiscal')->get($documento->storage_path));
    }

    public function test_does_not_expose_the_internal_storage_path(): void
    {
        [, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200));

        $this->assertArrayNotHasKey('storage_path', (new FiscalDocumentResource($document))->resolve());
    }

    public function test_writes_the_three_metadata_columns_the_parser_already_extracted(): void
    {
        [, $client] = $this->tenant();

        // Emitente, destinatário e valor chegam prontos da camada de parse: se o
        // writer não os gravasse, o painel perderia os três filtros sem nenhum
        // erro — e a alternativa (reparsear o XML aqui) criaria uma segunda
        // fonte para os mesmos três valores.
        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(
            200,
            emitenteCnpj: '11222333000181',
            destinatarioCnpj: '99887766000199',
            valorTotal: '1500.75',
        ));

        $this->assertSame('11222333000181', $document->emitente_cnpj);
        $this->assertSame('99887766000199', $document->destinatario_cnpj);
        $this->assertSame('1500.75', $document->valor_total);
    }

    public function test_keeps_the_raw_bytes_of_the_xml(): void
    {
        [, $client] = $this->tenant();

        // Byte Latin-1 isolado: a regra do módulo é guardar os bytes crus e
        // normalizar só na leitura, porque o serviço às vezes entrega byte
        // inválido e o arquivo gravado precisa ser exatamente o que chegou.
        $xml = "<resNFe><xNome>Caf\xE9</xNome></resNFe>";

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, xml: $xml));

        $this->assertSame($xml, Storage::disk('fiscal')->get($document->storage_path));
        $this->assertSame(hash('sha256', $xml), $document->sha256);
        $this->assertSame(strlen($xml), $document->xml_bytes);
    }

    public function test_marks_the_digest_as_matching_when_both_stages_agree(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        // Resumo na posição A, documento autorizado na B: dois SHA-1 batendo
        // provam que o XML completo é o que o ambiente nacional catalogou. Cada
        // etapa guarda o seu digest na sua linha, e a comparação é entre as duas.
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $document = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::DIGVAL));

        $this->assertSame(self::DIGVAL, $document->digval);
        $this->assertTrue($document->digval_confere);
    }

    public function test_marks_the_digest_as_divergent_instead_of_discarding_the_document(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $document = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::OUTRO_DIGVAL));

        // Divergência é a resposta para resposta truncada, payload misturado ou
        // documento trocado: o documento é gravado e marcado, nunca descartado —
        // descartar perderia um documento fiscal que existe.
        $this->assertFalse($document->digval_confere);
        $this->assertSame(2, FiscalDocument::count());
        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));
    }

    public function test_a_divergence_survives_the_reprocessing_of_the_same_batch(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $resumo = $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL);
        $completo = $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::OUTRO_DIGVAL);

        $writer->store($client, FiscalSource::NfeDistribuicao, $resumo);
        $writer->store($client, FiscalSource::NfeDistribuicao, $completo);

        // Reprocessar o mesmo lote é caminho normal, não exceção: a segunda
        // entrega do documento completo encontra o digest do resumo e diverge de
        // novo. Comparar a linha com ela mesma devolveria `true` e apagaria o
        // achado sem nenhum erro em lugar nenhum.
        $reprocessado = $writer->store($client, FiscalSource::NfeDistribuicao, $completo);

        $this->assertFalse($reprocessado->digval_confere);
        $this->assertSame(self::OUTRO_DIGVAL, $reprocessado->digval);
        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_a_divergence_is_reported_again_when_the_batch_is_reprocessed(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $resumo = $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL);
        $completo = $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::OUTRO_DIGVAL);

        $writer->store($client, FiscalSource::NfeDistribuicao, $resumo);
        $writer->store($client, FiscalSource::NfeDistribuicao, $completo);

        Log::spy();

        $writer->store($client, FiscalSource::NfeDistribuicao, $completo);

        // O aviso é a única saída do achado, então ele também tem de sobreviver
        // ao reprocessamento: um documento adulterado que só é relatado na
        // primeira entrega é um documento adulterado que o operador nunca vê.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.capture.digval_divergente'
                && $context['chave_acesso'] === self::CHAVE
                && $context['nsu'] === 250);
    }

    public function test_no_divergence_is_fabricated_when_the_full_document_arrives_first(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        // Nada ordena o lote por etapa, só por posição: a consulta por chave
        // devolve o documento autorizado sem o resumo, e o resumo pode ser
        // reentregue numa posição posterior. Um par que **bate** tem de continuar
        // batendo nos dois sentidos — senão cada documento saudável produz um
        // aviso falso, e é o aviso falso que treina quem lê a ignorar a linha.
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::DIGVAL));
        $resumo = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));

        $this->assertTrue($resumo->digval_confere);
        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_the_order_of_the_two_stages_does_not_change_the_verdict(): void
    {
        [, $summaryFirst] = $this->tenant();
        [, $documentFirst] = $this->tenant();

        $writer = $this->writer();

        $resumo = $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL);
        $completo = $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::OUTRO_DIGVAL);

        $writer->store($summaryFirst, FiscalSource::NfeDistribuicao, $resumo);
        $writer->store($summaryFirst, FiscalSource::NfeDistribuicao, $completo);

        $writer->store($documentFirst, FiscalSource::NfeDistribuicao, $completo);
        $writer->store($documentFirst, FiscalSource::NfeDistribuicao, $resumo);

        // Mesmo par divergente, duas ordens de chegada, o mesmo resultado: quem
        // chega por último descobre a divergência nos dois casos. Ler o digest da
        // própria linha daria `compare(Y, Y)` na segunda entrega do documento
        // completo e fabricaria uma divergência no par que bate.
        $this->assertFalse($this->rowOf($summaryFirst, FiscalStage::Document)->digval_confere);
        $this->assertFalse($this->rowOf($documentFirst, FiscalStage::Summary)->digval_confere);

        // E a linha que chegou primeiro continua sem veredito — no momento em que
        // foi gravada, a etapa parceira ainda não existia, e "não dá para dizer"
        // era a única resposta honesta. Reentregá-la resolve: a comparação passa
        // a ler a etapa parceira, que nunca é a linha sendo gravada.
        $this->assertNull($this->rowOf($summaryFirst, FiscalStage::Summary)->digval_confere);

        $writer->store($summaryFirst, FiscalSource::NfeDistribuicao, $resumo);

        $this->assertFalse($this->rowOf($summaryFirst, FiscalStage::Summary)->digval_confere);
    }

    public function test_records_the_digest_without_a_verdict_when_the_other_stage_never_arrived(): void
    {
        [, $client] = $this->tenant();

        // Uma captura que começa no meio da fila nunca vê o resumo do documento,
        // e um resumo sozinho não tem com que conferir: o veredito é "não dá
        // para dizer", que é uma terceira resposta — e não um "não confere" que
        // acusaria de corrupção o documento comum.
        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));

        $this->assertSame(self::DIGVAL, $document->digval);
        $this->assertNull($document->digval_confere);
    }

    public function test_records_the_digest_without_a_verdict_for_a_document_with_no_counterpart(): void
    {
        [, $client] = $this->tenant();

        // O outro lado do mesmo estado: um documento autorizado cuja consulta por
        // chave devolveu o XML completo e nunca viu o resumo.
        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::DIGVAL));

        $this->assertSame(self::DIGVAL, $document->digval);
        $this->assertNull($document->digval_confere);
    }

    public function test_records_no_digest_for_a_document_without_one(): void
    {
        [, $client] = $this->tenant();

        // Evento não tem `digVal`: o digest some como nulo, e a linha do evento
        // não herda o da nota — a etapa e a chave composta são o que separa as
        // três linhas.
        $writer = $this->writer();

        $nota = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $evento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event));

        $this->assertSame(self::DIGVAL, $nota->digval);
        $this->assertNull($evento->digval);
        $this->assertNull($evento->digval_confere);
    }

    public function test_logs_a_warning_naming_the_document_whose_digest_diverged(): void
    {
        [, $client] = $this->tenant();

        Log::spy();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::OUTRO_DIGVAL));

        // Uma coluna que ninguém lê não marca nada: a linha continua no banco,
        // o painel a conta como documento normal e o download a serve como se
        // estivesse íntegra. O log é o que dá a divergência como achado, e ele
        // diz qual cliente, qual chave e qual posição — a chave de acesso é
        // identificador fiscal público, não dado pessoal.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($client): bool {
                $this->assertSame('fiscal.capture.digval_divergente', $message);
                $this->assertSame($client->getKey(), $context['client_id']);
                $this->assertSame(self::CHAVE, $context['chave_acesso']);
                $this->assertSame(250, $context['nsu']);

                // Nem digest, nem payload, nem XML: o valor dos dois digests
                // fica na linha, e o log carrega só o fato. Quem for atrás do
                // documento tem a linha; o log não precisa duplicar o segredo
                // de nada.
                $this->assertStringNotContainsString(self::DIGVAL, serialize($context));
                $this->assertStringNotContainsString(self::OUTRO_DIGVAL, serialize($context));

                return true;
            });
    }

    public function test_does_not_log_a_warning_when_the_digest_matches(): void
    {
        [, $client] = $this->tenant();

        Log::spy();

        $writer = $this->writer();

        // O caso comum: duas etapas que batem é a integridade funcionando, e é
        // o que acontece em praticamente toda captura. Logar aqui seria ruído que
        // treina quem lê a ignorar a linha.
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: self::DIGVAL));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_log_a_warning_when_there_is_no_second_digest(): void
    {
        [, $client] = $this->tenant();

        Log::spy();

        $writer = $this->writer();

        // "Não dá para dizer" não é achado: é o estado de todo documento de etapa
        // única, e de toda captura que começa no meio da fila. Logar aqui marcaria
        // como corrupção o documento comum.
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_refuses_an_access_key_that_does_not_close(): void
    {
        [, $client] = $this->tenant();

        // A chave do caso tem os 44 dígitos e um dígito verificador errado: ela
        // passa em qualquer conferência de comprimento e mesmo assim não é uma
        // chave. Higienizá-la e interpolar no caminho colocaria um documento
        // numa pasta que não é a dele, sem erro em lugar nenhum — então o writer
        // recusa, e nada é gravado.
        try {
            $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, chave: str_pad('1234', 44, '0', STR_PAD_LEFT)));
            $this->fail('Uma chave de acesso que não fecha não deveria virar caminho.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chave de acesso', strtolower($e->getMessage()));
        }

        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame([], Storage::disk('fiscal')->allFiles());
    }

    public function test_refuses_an_event_id_outside_the_published_shape(): void
    {
        [, $client] = $this->tenant();

        // O `event_id` vem de `tpEvento` e `nSeqEvento` lidos do XML, e um dos
        // dois com valor adulterado atravessa a validação de chave: a guarda do
        // writer é o que impede que ele saia do diretório do cliente.
        try {
            $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, '110111/../../..'));
            $this->fail('Um event_id fora do formato não deveria virar caminho.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('evento', strtolower($e->getMessage()));
        }

        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame([], Storage::disk('fiscal')->allFiles());
    }

    private function writer(): FiscalDocumentWriter
    {
        return resolve(FiscalDocumentWriter::class);
    }

    private function rowOf(Client $client, FiscalStage $stage): FiscalDocument
    {
        return FiscalDocument::query()
            ->where('client_id', $client->getKey())
            ->where('stage', $stage->value)
            ->firstOrFail();
    }

    /** @return array{0: Account, 1: Client} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        return [$account, $client];
    }

    /**
     * Documento como o serviço o entrega, com o que cada etapa traz: o resumo e o
     * documento autorizado carregam `digVal`, o evento não; emitente,
     * destinatário e valor só aparecem quando o XML os traz.
     *
     * A etapa vem por parâmetro porque o `PulledDocument` é a fronteira entre o
     * conector e a gravação: quem sabe em que etapa a entrega caiu é a camada de
     * parse, e o writer não reparseia o XML para descobrir.
     */
    private function pulled(
        int $nsu = 200,
        string $eventId = '',
        FiscalKind $kind = FiscalKind::Document,
        string $xml = '<resNFe/>',
        ?string $digVal = null,
        ?string $emitenteCnpj = null,
        ?string $destinatarioCnpj = null,
        ?string $valorTotal = null,
        ?string $chave = null,
        FiscalStage $stage = FiscalStage::Document,
    ): PulledDocument {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: $kind,
            stage: $stage,
            chave: $chave ?? self::CHAVE,
            eventId: $eventId,
            emitenteCnpj: $emitenteCnpj,
            destinatarioCnpj: $destinatarioCnpj,
            valorTotal: $valorTotal,
            digVal: $digVal,
            nsu: $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: $xml,
        );
    }
}
