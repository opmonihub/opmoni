<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Capture\FiscalXmlPath;
use App\Services\Fiscal\Contracts\PulledDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * O writer: o único caminho de escrita do módulo.
 *
 * O que estes testes fixam é a identidade — reprocessar o mesmo lote não cria
 * linha extra, e as várias etapas de distribuição da mesma chave continuam
 * convivendo — e a ordem dentro de `store()`: o arquivo antes da linha.
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
     * SHA-1 em base64: os 28 caracteres que um `digVal` sempre tem. O segundo
     * é outro SHA-1 de 20 bytes, para o caso divergente.
     */
    private const DIGVAL = 'L0xl/8X3vX0gk0m3sQ0m0L0Y8X3vX0g=';

    private const OUTRO_DIGVAL = '9fX19fX19fX19fX19fX19fX19fU=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
    }

    public function test_stores_the_document_with_its_xml_on_the_private_disk(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));

        $this->assertSame($account->getKey(), $document->account_id);
        $this->assertSame($client->getKey(), $document->client_id);
        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));
        $this->assertSame(hash('sha256', '<resNFe/>'), $document->sha256);
        $this->assertSame(9, $document->xml_bytes);
    }

    public function test_stores_the_path_the_shared_contract_derives(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));

        // A coluna é lida de volta por quem serve o download, então o caminho
        // gravado tem de ser o de `FiscalXmlPath::for()` — o mesmo que a factory
        // deriva, e não uma segunda conta feita dentro do writer.
        $this->assertSame(
            FiscalXmlPath::for((int) $account->getKey(), (int) $client->getKey(), self::CHAVE, ''),
            $document->storage_path,
        );
    }

    public function test_reprocessing_the_same_document_overwrites_instead_of_duplicating(): void
    {
        [, $client] = $this->tenant();

        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));
        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));

        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_reprocessing_replaces_the_payload_of_the_same_row(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $first = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));
        $second = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1300, xml: '<nfeProc/>'));

        // Mesma linha, conteúdo novo: sobrescrever é gravar por cima, e o
        // arquivo em disco é o da última entrega, com o hash e o tamanho
        // conferindo com os bytes que ele aponta.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1300, $second->nsu);
        $this->assertSame('<nfeProc/>', Storage::disk('fiscal')->get($second->storage_path));
        $this->assertSame(hash('sha256', '<nfeProc/>'), $second->sha256);
        $this->assertSame(10, $second->xml_bytes);
    }

    public function test_event_is_stored_alongside_its_document(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $documento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));
        $evento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1300, '110111-1', FiscalKind::Event));

        $this->assertSame(2, FiscalDocument::count());

        // Mesma chave, mesmo arquivo? Não: o `event_id` é o que separa as etapas
        // de distribuição, e um evento que sobrescrevesse o XML da nota seria um
        // documento que baixa o arquivo de outro.
        $this->assertNotSame($documento->storage_path, $evento->storage_path);
        $this->assertTrue(Storage::disk('fiscal')->exists($documento->storage_path));
        $this->assertTrue(Storage::disk('fiscal')->exists($evento->storage_path));
        $this->assertSame('<resNFe/>', Storage::disk('fiscal')->get($documento->storage_path));
    }

    public function test_does_not_expose_the_internal_storage_path(): void
    {
        [, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234));

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
            1234,
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

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, xml: $xml));

        $this->assertSame($xml, Storage::disk('fiscal')->get($document->storage_path));
        $this->assertSame(hash('sha256', $xml), $document->sha256);
        $this->assertSame(strlen($xml), $document->xml_bytes);
    }

    public function test_marks_the_digest_as_matching_when_both_stages_agree(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        // Resumo na posição A, documento autorizado na B: as duas etapas caem na
        // mesma linha da chave composta, e é a segunda que encontra o digest da
        // primeira — dois SHA-1 batendo provam que o XML completo é o que o
        // ambiente nacional catalogou.
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, digVal: self::DIGVAL));
        $document = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1300, xml: '<nfeProc/>', digVal: self::DIGVAL));

        $this->assertSame(self::DIGVAL, $document->digval);
        $this->assertTrue($document->digval_confere);
    }

    public function test_marks_the_digest_as_divergent_instead_of_discarding_the_document(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, digVal: self::DIGVAL));
        $document = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1300, xml: '<nfeProc/>', digVal: self::OUTRO_DIGVAL));

        // Divergência é a resposta para resposta truncada, payload misturado ou
        // documento trocado: o documento é gravado e marcado, nunca descartado —
        // descartar perderia um documento fiscal que existe.
        $this->assertFalse($document->digval_confere);
        $this->assertSame(1, FiscalDocument::count());
        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));
    }

    public function test_records_the_digest_without_a_verdict_when_the_other_stage_never_arrived(): void
    {
        [, $client] = $this->tenant();

        // Uma captura que começa no meio da fila nunca vê o resumo do documento,
        // e um resumo sozinho não tem com o que conferir: o veredito é "não dá
        // para dizer", que é uma terceira resposta — e não um "não confere" que
        // acusaria de corrupção o documento comum.
        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, digVal: self::DIGVAL));

        $this->assertSame(self::DIGVAL, $document->digval);
        $this->assertNull($document->digval_confere);
    }

    public function test_records_no_digest_for_a_document_without_one(): void
    {
        [, $client] = $this->tenant();

        // Evento não tem `digVal`: o digest some como nulo, e a linha do evento
        // não herda o da nota — a chave composta é o que separa as duas.
        $writer = $this->writer();

        $nota = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, digVal: self::DIGVAL));
        $evento = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1300, '110111-1', FiscalKind::Event));

        $this->assertSame(self::DIGVAL, $nota->digval);
        $this->assertNull($evento->digval);
        $this->assertNull($evento->digval_confere);
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
            $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, chave: str_pad('1234', 44, '0', STR_PAD_LEFT)));
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
            $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(1234, '110111/../../..'));
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

    /** @return array{0: Account, 1: Client} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        return [$account, $client];
    }

    /**
     * Documento como o serviço o entrega, com o que cada etapa traz: o resumo e
     * o documento autorizado carregam `digVal`, o evento não; emitente,
     * destinatário e valor só aparecem quando o XML os traz.
     */
    private function pulled(
        int $nsu = 1234,
        string $eventId = '',
        FiscalKind $kind = FiscalKind::Document,
        string $xml = '<resNFe/>',
        ?string $digVal = null,
        ?string $emitenteCnpj = null,
        ?string $destinatarioCnpj = null,
        ?string $valorTotal = null,
        ?string $chave = null,
    ): PulledDocument {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: $kind,
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
