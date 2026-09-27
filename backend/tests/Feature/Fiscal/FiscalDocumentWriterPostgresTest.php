<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O writer contra o Postgres de verdade, e não o sqlite de `phpunit.xml`.
 *
 * O sqlite não aplica o comprimento declarado de um `varchar`, então um digest de
 * 32 caracteres numa coluna `varchar(28)` passava em toda a suíte e rebentava em
 * produção com *value too long*. Este teste roda em Postgres, pega a
 * configuração de `.env` e usa um banco próprio, então o que ele exercita é a
 * coluna que a produção tem.
 *
 * Os demais testes do writer rodam em sqlite porque o que eles fixam é identidade,
 * ordem e veredito — nenhum deles depende do banco. Este existe só para o
 * comprimento, a unicidade com a etapa e o `NOT NULL`, que é o que o sqlite
 * deixa passar.
 */
class FiscalDocumentWriterPostgresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SHA-1 de 20 bytes em base64: 28 caracteres, o que `varchar(28)` aceita.
     */
    private const DIGVAL = 'i2rqNaD6rqmCfhXHyTBf4xe1ImQ=';

    private const CHAVE = '35220499999999999999550010020000001240556600';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requer o Postgres de .env; a suíte padrão roda em sqlite.');
        }

        // Disco local de verdade: o disco `fiscal` aponta para
        // `storage/app/private/fiscal`, e o XML gravado aqui é sintético e não
        // tem dado de terceiro. O diretório sai junto no fim do teste.
        Storage::disk('fiscal')->deleteDirectory('');
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'pgsql') {
            Storage::disk('fiscal')->deleteDirectory('');
        }

        parent::tearDown();
    }

    public function test_a_real_digest_of_twenty_bytes_fits_the_column(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(
            200,
            stage: FiscalStage::Summary,
            digVal: self::DIGVAL,
        ));

        $this->assertSame($account->getKey(), $document->account_id);
        $this->assertSame(self::DIGVAL, $document->fresh()?->digval);
        $this->assertSame(28, strlen(self::DIGVAL));
    }

    public function test_a_digest_longer_than_the_column_is_refused_by_postgres(): void
    {
        [, $client] = $this->tenant();

        // O outro lado da mesma verdade: o `varchar(28)` do Postgres recusa o
        // valor de 32 caracteres que o sqlite aceitou em silêncio, e é essa
        // recusa que impede um digest truncado de ser gravado como se fosse o
        // digest do documento.
        $this->expectException(QueryException::class);

        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled(
            200,
            stage: FiscalStage::Summary,
            digVal: 'L0xl/8X3vX0gk0m3sQ0m0L0Y8X3vX0g=',
        ));
    }

    public function test_the_three_stages_survive_the_real_unique_index(): void
    {
        [, $client] = $this->tenant();

        $writer = $this->writer();

        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: 'uZskzthn678Zkeu/V/kJkmfqywY='));
        $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event));

        // A unicidade real é `(client_id, chave_acesso, stage, event_id)`, e ela
        // é o que barra a duplicata de verdade. Reprocessar as três etapas não
        // cria uma linha a mais.
        $this->assertSame(3, FiscalDocument::count());

        foreach ([$this->pulled(200, stage: FiscalStage::Summary, digVal: self::DIGVAL), $this->pulled(250, xml: '<nfeProc/>', stage: FiscalStage::Document, digVal: 'uZskzthn678Zkeu/V/kJkmfqywY='), $this->pulled(300, '110111-1', FiscalKind::Event, stage: FiscalStage::Event)] as $document) {
            $writer->store($client, FiscalSource::NfeDistribuicao, $document);
        }

        $this->assertSame(3, FiscalDocument::count());
        $this->assertFalse(FiscalDocument::query()->where('stage', FiscalStage::Document->value)->firstOrFail()->digval_confere);
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

    private function pulled(
        int $nsu = 200,
        string $eventId = '',
        FiscalKind $kind = FiscalKind::Document,
        string $xml = '<resNFe/>',
        ?string $digVal = null,
        FiscalStage $stage = FiscalStage::Document,
    ): PulledDocument {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: $kind,
            stage: $stage,
            chave: self::CHAVE,
            eventId: $eventId,
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: $digVal,
            nsu: $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: $xml,
        );
    }
}
