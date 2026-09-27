<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        FiscalDocument::factory()->create($this->documentAttributes($account, $client, '1'));
        FiscalDocument::factory()->create($this->documentAttributes($account, $client, '2'));

        $this->assertSame(2, FiscalDocument::count());
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

    public function test_fiscal_disk_is_private(): void
    {
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));
        Storage::disk('fiscal')->put('probe.txt', 'x');
        $this->assertTrue(Storage::disk('fiscal')->exists('probe.txt'));
        Storage::disk('fiscal')->delete('probe.txt');
    }

    public function test_fiscal_disk_root_is_outside_the_public_directory(): void
    {
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));
        $this->assertStringStartsWith(storage_path('app/private'), config('filesystems.disks.fiscal.root'));
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
        $this->assertNotSame('', $event->event_id);
        $this->assertNotNull($event->evento_ocorrido_em_at);
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
}
