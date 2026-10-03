<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalXmlPath;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Tenant\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FiscalSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O disco `fiscal` guarda XML de terceiro, com dados pessoais. A
        // configuração continua sendo a real — `Storage::fake` só troca a raiz
        // —, mas o teste não escreve arquivo nenhum em `storage/app/private`.
        Storage::fake('fiscal');
    }

    public function test_document_is_unique_per_client_and_access_key(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));

        $this->expectException(QueryException::class);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));
    }

    public function test_many_documents_without_event_coexist(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        // `event_id` fica de fora nos dois inserts: o valor gravado é o default
        // do banco, não um valor escolhido pelo teste.
        DB::table('fiscal_documents')->insert($this->rawDocument($account, $client, '1'));
        DB::table('fiscal_documents')->insert($this->rawDocument($account, $client, '2'));

        $this->assertSame(2, FiscalDocument::withoutGlobalScope('account')->count());

        $eventIds = DB::table('fiscal_documents')->orderBy('nsu')->pluck('event_id');

        // String vazia, não `null`: é esse valor não-nulo que a restrição
        // única consegue comparar.
        $this->assertSame(['', ''], $eventIds->all());
    }

    public function test_duplicate_document_relying_on_the_default_event_id_is_rejected(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        DB::table('fiscal_documents')->insert($this->rawDocument($account, $client, '1'));

        $this->expectException(QueryException::class);

        // Mesma chave, mesmo default. Se `event_id` fosse nullable, as duas
        // linhas carregariam `NULL` e o Postgres — onde `NULL != NULL` — aceitaria
        // as duas: a unicidade evaporaria justamente nos documentos comuns,
        // que são os que não têm evento.
        DB::table('fiscal_documents')->insert($this->rawDocument($account, $client, '1'));
    }

    public function test_event_id_null_is_refused_by_the_database(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $this->expectException(QueryException::class);

        // `null` explícito, não a ausência da coluna: a ausência cairia no
        // default `''` e o `NOT NULL` nem seria exercitado.
        DB::table('fiscal_documents')->insert([
            ...$this->rawDocument($account, $client, '1'),
            'event_id' => null,
        ]);
    }

    public function test_event_and_document_share_an_access_key(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));
        FiscalDocument::factory()->event()->create($this->documentAttributes($account, $client));

        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_cursor_is_unique_per_client_and_source(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalCursor::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
        ]);

        $this->expectException(QueryException::class);

        FiscalCursor::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
        ]);
    }

    public function test_conta_nao_enxerga_documento_nem_cursor_de_outra(): void
    {
        $conta = Account::factory()->create();
        $outra = Account::factory()->create();

        resolve(CurrentTenant::class)->accountId = $conta->getKey();

        $cliente = Client::factory()->individual()->create(['account_id' => $conta->getKey()]);
        $documento = FiscalDocument::factory()->create(['client_id' => $cliente->getKey()]);
        $cursor = FiscalCursor::factory()->create(['client_id' => $cliente->getKey()]);

        $clienteEstrangeiro = Client::factory()->individual()->create(['account_id' => $outra->getKey()]);
        $documentoEstrangeiro = FiscalDocument::factory()->create([
            'account_id' => $outra->getKey(),
            'client_id' => $clienteEstrangeiro->getKey(),
        ]);
        $cursorEstrangeiro = FiscalCursor::factory()->create([
            'account_id' => $outra->getKey(),
            'client_id' => $clienteEstrangeiro->getKey(),
        ]);

        $this->assertSame($outra->getKey(), $documentoEstrangeiro->account_id);
        $this->assertSame($outra->getKey(), $cursorEstrangeiro->account_id);

        resolve(CurrentTenant::class)->accountId = $conta->getKey();

        $this->assertSame([$documento->getKey()], FiscalDocument::query()->pluck('id')->all());
        $this->assertSame([$cursor->getKey()], FiscalCursor::query()->pluck('id')->all());
        $this->assertNull(FiscalDocument::find($documentoEstrangeiro->getKey()));
        $this->assertNull(FiscalCursor::find($cursorEstrangeiro->getKey()));

        // A linha estrangeira existe de verdade: quem esconde é o escopo global
        // de conta, não um insert que não aconteceu.
        $this->assertTrue(
            FiscalDocument::withoutGlobalScope('account')->whereKey($documentoEstrangeiro->getKey())->exists()
        );
        $this->assertTrue(
            FiscalCursor::withoutGlobalScope('account')->whereKey($cursorEstrangeiro->getKey())->exists()
        );
    }

    public function test_the_summary_and_the_full_document_of_one_key_coexist(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        // A spec: resumo e documento completo são duas entregas de distribuição do
        // mesmo documento, e as duas ficam recuperáveis pela chave de acesso.
        // Com a etapa fora da chave composta, as duas linhas colidiam e o
        // documento completo sobrescrevia o resumo.
        // A mesma chave de acesso nos dois inserts: sem a etapa na chave, estes
        // dois eram um só.
        FiscalDocument::factory()->create([
            ...$this->documentAttributes($account, $client),
            'stage' => FiscalStage::Summary,
        ]);

        FiscalDocument::factory()->create([
            ...$this->documentAttributes($account, $client),
            'stage' => FiscalStage::Document,
        ]);

        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_reprocessing_one_stage_of_a_key_is_rejected_as_a_duplicate(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create([
            ...$this->documentAttributes($account, $client),
            'stage' => FiscalStage::Document,
        ]);

        $this->expectException(QueryException::class);

        // Reprocessar o mesmo lote reentrega a mesma etapa, e é a unicidade que
        // transforma a reentrega em sobrescrita em vez de linha duplicada.
        FiscalDocument::factory()->create([
            ...$this->documentAttributes($account, $client),
            'stage' => FiscalStage::Document,
        ]);
    }

    public function test_document_carries_the_digest_and_its_verdict(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $this->assertTrue(Schema::hasColumns('fiscal_documents', ['digval', 'digval_confere']));

        // A verificação de integridade do módulo (decisão 8) tem três respostas —
        // confere, não confere e não dá para dizer — e o banco é quem guarda a
        // diferença: uma coluna que só aceitasse verdadeiro e falso acusaria de
        // corrompido o documento que chegou sozinho, sem a outra etapa para
        // comparar.
        FiscalDocument::factory()->create([
            'client_id' => $client->getKey(),
            'digval' => 'i2rqNaD6rqmCfhXHyTBf4xe1ImQ=',
            'digval_confere' => false,
        ]);

        $divergente = FiscalDocument::query()->firstOrFail();
        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $divergente->digval);
        $this->assertFalse($divergente->digval_confere);

        // Nulo é um estado, não uma ausência: as duas colunas precisam aceitá-lo.
        DB::table('fiscal_documents')->insert([
            ...$this->rawDocument($account, $client, '9'),
            'digval' => null,
            'digval_confere' => null,
        ]);

        $semVeredito = FiscalDocument::query()->where('nsu', 9)->firstOrFail();
        $this->assertNull($semVeredito->digval);
        $this->assertNull($semVeredito->digval_confere);
    }

    public function test_fiscal_disk_is_private(): void
    {
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));
        Storage::disk('fiscal')->put('probe.txt', 'x');
        $this->assertTrue(Storage::disk('fiscal')->exists('probe.txt'));
        Storage::disk('fiscal')->delete('probe.txt');
    }

    public function test_fiscal_disk_root_is_not_under_public_directory(): void
    {
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));
        $this->assertStringNotContainsString('public', config('filesystems.disks.fiscal.root'));
    }

    public function test_fiscal_disk_registers_no_public_route(): void
    {
        // `serve => false` é o que impede o Laravel de registrar a rota
        // `storage/{path}` do disco `fiscal`. Sem isso, o XML cairia numa rota
        // anônima.
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'storage.fiscal'))
            ->values()
            ->all();

        $this->assertSame([], $routes);
    }

    public function test_document_factory_fills_every_not_nullable_column(): void
    {
        $document = FiscalDocument::factory()->create();

        $this->assertNotNull($document->account_id);
        $this->assertNotNull($document->client_id);
        $this->assertInstanceOf(FiscalSource::class, $document->source);
        $this->assertInstanceOf(FiscalModel::class, $document->model);
        $this->assertInstanceOf(FiscalKind::class, $document->kind);
        $this->assertInstanceOf(FiscalStage::class, $document->stage);
        $this->assertSame(44, strlen($document->chave_acesso));
        $this->assertSame('', $document->event_id);
        $this->assertGreaterThan(0, $document->nsu);
        $this->assertNotSame('', $document->storage_path);
        $this->assertSame(64, strlen($document->sha256));
        $this->assertGreaterThan(0, $document->xml_bytes);
        $this->assertNotNull($document->captured_at);
    }

    public function test_event_state_marks_the_document_as_an_event(): void
    {
        $event = FiscalDocument::factory()->event()->create();

        $this->assertSame(FiscalKind::Event, $event->kind);
        $this->assertSame(FiscalStage::Event, $event->stage);
        $this->assertNotSame('', $event->event_id);
        $this->assertNotNull($event->evento_ocorrido_em_at);
    }

    /**
     * A largura de 50 é da NFS-e nacional, e a coluna alargada pela migration é
     * o que a faz entrar: uma factory que truca a chave esconderia o INSERT
     * quebrado do schema antigo.
     */
    public function test_nfse_state_carries_a_fifty_digit_key_with_valid_check_digit(): void
    {
        $document = FiscalDocument::factory()->nfse()->create();

        $this->assertSame(FiscalSource::NfseAdn, $document->source);
        $this->assertSame(FiscalModel::Nfse, $document->model);
        $this->assertSame(50, strlen($document->chave_acesso));
        // O dígito verificador da chave criada é o que o módulo valida — a
        // mesma conta que a captura real aplica antes de gravar.
        $this->assertTrue(FiscalXmlMetadata::isValidChave($document->chave_acesso));
    }

    public function test_summary_state_marks_the_document_as_a_summary_stage(): void
    {
        $summary = FiscalDocument::factory()->summary()->create();

        $this->assertSame(FiscalKind::Document, $summary->kind);
        $this->assertSame(FiscalStage::Summary, $summary->stage);
        $this->assertSame('', $summary->event_id);
        $this->assertStringEndsWith('-resumo.xml', $summary->storage_path);
    }

    public function test_fiscal_xml_path_format_is_stable(): void
    {
        $chave = str_repeat('3', 44);

        $this->assertSame("7/9/{$chave}-documento.xml", FiscalXmlPath::for(7, 9, $chave, '', FiscalStage::Document));
        $this->assertSame("7/9/{$chave}-resumo.xml", FiscalXmlPath::for(7, 9, $chave, '', FiscalStage::Summary));
        $this->assertSame("7/9/{$chave}-110111.xml", FiscalXmlPath::for(7, 9, $chave, '110111', FiscalStage::Event));
    }

    public function test_the_two_document_stages_do_not_share_a_file(): void
    {
        $chave = str_repeat('3', 44);

        // Resumo e documento completo têm a mesma chave de acesso e o mesmo
        // `event_id` vazio: se o caminho não carregasse a etapa, o segundo
        // sobrescreveria o XML do primeiro em disco, e o download da linha do
        // resumo serviria o documento completo.
        $this->assertNotSame(
            FiscalXmlPath::for(7, 9, $chave, '', FiscalStage::Summary),
            FiscalXmlPath::for(7, 9, $chave, '', FiscalStage::Document),
        );
    }

    public function test_storage_path_follows_the_shared_fiscal_xml_path_contract(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $document = FiscalDocument::factory()->create(['client_id' => $client->getKey()]);
        $event = FiscalDocument::factory()->event()->create(['client_id' => $client->getKey()]);

        // Sem esta igualdade, a factory pode escrever num caminho e o download
        // ler outro: os dois estariam coerentes consigo mesmos e nenhum teste
        // perceberia a divergência da produção.
        $this->assertSame(
            FiscalXmlPath::for((int) $account->getKey(), (int) $client->getKey(), (string) $document->chave_acesso, '', FiscalStage::Document),
            $document->storage_path,
        );

        $this->assertSame(
            FiscalXmlPath::for((int) $account->getKey(), (int) $client->getKey(), (string) $event->chave_acesso, '110111', FiscalStage::Event),
            $event->storage_path,
        );
    }

    public function test_stored_xml_state_puts_the_payload_on_the_fiscal_disk(): void
    {
        $document = FiscalDocument::factory()->withStoredXml()->create();

        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));

        $bytes = Storage::disk('fiscal')->get($document->storage_path);

        $this->assertStringContainsString('<nfeProc', $bytes);
        $this->assertSame(hash('sha256', $bytes), $document->sha256);
        $this->assertSame(strlen($bytes), $document->xml_bytes);
    }

    public function test_stored_xml_state_refuses_to_write_when_fiscal_disk_is_not_faked(): void
    {
        // Desfaz o fake do setUp: o disco volta a resolver para a raiz real.
        app('filesystem')->forgetDisk('fiscal');

        $before = Storage::disk('fiscal')->allFiles();

        try {
            FiscalDocument::factory()->withStoredXml()->create();
            $this->fail('withStoredXml() deveria recusar gravar no disco fiscal de verdade.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Storage::fake('fiscal')", $e->getMessage());
        }

        // Falhou antes do `put`: nenhum XML de terceiro foi parar no `storage/`
        // da máquina de desenvolvimento, e nada foi persistido.
        $this->assertSame($before, Storage::disk('fiscal')->allFiles());
        $this->assertSame(0, FiscalDocument::count());
    }

    /**
     * Identidade comum às duas linhas de um mesmo documento: mesma chave de
     * acesso, mesmo NSU. `kind` e `event_id` ficam de fora de propósito —
     * `create($attributes)` anexa os atributos como um estado *posterior*, que
     * sobrescreveria o `event()`, cancelando o próprio estado que o teste quer
     * exercitar.
     *
     * @return array<string, mixed>
     */
    private function documentAttributes(Account $account, Client $client, string $nsu = '1'): array
    {
        return [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'chave_acesso' => str_pad($nsu, 44, '0', STR_PAD_LEFT),
            'nsu' => (int) $nsu,
        ];
    }

    /**
     * Linha crua de `fiscal_documents`, com `event_id` deliberadamente ausente
     * para o default do banco agir.
     *
     * Inserção por `DB::table` de propósito, e não pelo model: o model e a
     * factory preenchem `event_id` sempre, então só um insert cru deixa o
     * default entrar. Se alguém "simplificar" estes testes para chamadas de
     * model, a garantia some — e é exatamente a garantia que a coluna
     * `NOT NULL` default `''` existe para dar.
     *
     * @return array<string, mixed>
     */
    private function rawDocument(Account $account, Client $client, string $nsu): array
    {
        $chave = str_pad($nsu, 44, '0', STR_PAD_LEFT);

        return [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao->value,
            'model' => FiscalModel::Nfe->value,
            'kind' => FiscalKind::Document->value,
            'chave_acesso' => $chave,
            'nsu' => (int) $nsu,
            'storage_path' => FiscalXmlPath::for((int) $account->getKey(), (int) $client->getKey(), $chave),
            'sha256' => hash('sha256', $chave),
            'xml_bytes' => 10,
            'captured_at' => now(),
        ];
    }
}
