<?php

namespace Tests\Integration\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FiscalProcessingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires phpunit.pgsql.xml and isolated PostgreSQL/Redis.');
        }
        self::guardIsolation();
        Storage::fake('fiscal');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public static function guardIsolation(): void
    {
        if (app()->environment() !== 'testing'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || ! empty(config('database.connections.pgsql.url'))
            || config('database.redis.options.prefix') !== 'opmoni-access-test-'
            || config('cache.prefix') !== 'opmoni-access-test-cache-'
            || config('cache.default') !== 'redis'
            || config('session.driver') !== 'redis'
            || config('session.store') !== 'sessions') {
            throw new RuntimeException('Refusing non-isolated fiscal integration configuration.');
        }
        if (DB::selectOne('select current_database() as name')->name !== 'opmoni_test') {
            throw new RuntimeException('Refusing a non-test PostgreSQL connection.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $name => $database) {
            if ((int) config("database.redis.{$name}.database") !== $database
                || ! empty(config("database.redis.{$name}.url"))) {
                throw new RuntimeException('Refusing a non-test Redis connection: '.$name);
            }
            $client = Redis::connection($name)->client();
            if (! $client instanceof \Redis || $client->getDBNum() !== $database
                || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-') {
                throw new RuntimeException('Redis runtime database/prefix differs from the test configuration.');
            }
        }
    }

    public function test_registro_duplicado_de_ciencia_mantem_um_veredito(): void
    {
        [$account, $client] = $this->tenant();
        $store = resolve(FiscalManifestationStore::class);

        $primeiro = $store->registrarPedido(
            (int) $account->getKey(),
            (int) $client->getKey(),
            str_repeat('7', 44),
            FiscalManifestationEventType::CienciaEmissao,
            1,
            'integration',
        );
        $primeiro->forceFill(['outcome' => FiscalManifestationOutcome::Sent])->save();

        $segundo = $store->registrarPedido(
            (int) $account->getKey(),
            (int) $client->getKey(),
            str_repeat('7', 44),
            FiscalManifestationEventType::CienciaEmissao,
            1,
            'integration-repeat',
        );

        $this->assertSame($primeiro->getKey(), $segundo->getKey());
        $this->assertSame(FiscalManifestationOutcome::Sent, $segundo->fresh()->outcome);
        $this->assertSame(1, FiscalManifestation::withoutGlobalScope('account')->count());
    }

    public function test_claim_concorrente_de_processamento_permite_apenas_um(): void
    {
        [$account, $client] = $this->tenant();
        $summary = FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'chave_acesso' => str_repeat('8', 44),
        ]);

        $coordinator = resolve(FiscalProcessingCoordinator::class);

        $this->assertTrue($coordinator->claim($summary->fresh()));
        $this->assertFalse($coordinator->claim($summary->fresh()));
    }

    public function test_claim_expirado_libera_nova_execucao_apos_interrupcao(): void
    {
        [$account, $client] = $this->tenant();
        $summary = FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'chave_acesso' => str_repeat('6', 44),
        ]);

        $coordinator = resolve(FiscalProcessingCoordinator::class);
        $this->assertTrue($coordinator->claim($summary->fresh()));

        $summary->forceFill(['processing_claim_expires_at' => now()->subMinute()])->saveQuietly();

        $this->assertTrue($coordinator->claim($summary->fresh()));
    }

    public function test_publicacao_completa_idempotente_fecha_resumo_uma_vez(): void
    {
        [$account, $client] = $this->tenant();
        $chave = str_repeat('9', 44);
        $summary = FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'chave_acesso' => $chave,
            'processing_state' => FiscalProcessingState::AwaitingXml,
        ]);
        $document = FiscalDocument::factory()->withStoredXml()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'chave_acesso' => $chave,
            'stage' => FiscalStage::Document,
            'storage_state' => FiscalStorageState::Available,
        ]);

        $coordinator = resolve(FiscalProcessingCoordinator::class);
        $coordinator->afterCompleteDocumentStored($document->fresh());
        $coordinator->afterCompleteDocumentStored($document->fresh());

        $summary->refresh();
        $this->assertSame(FiscalProcessingState::Complete, $summary->processing_state);
        $this->assertNotNull($summary->processing_completed_at);
    }

    public function test_orcamento_compartilhado_de_consulta_reserva_ate_o_teto(): void
    {
        [, $client] = $this->tenant();
        config(['fiscal.consulta_hourly_limit' => 1]);
        $budget = resolve(FiscalLookupBudget::class);

        $this->assertTrue($budget->reserve($client));
        $this->assertFalse($budget->reserve($client));
    }

    /**
     * @return array{0: Account, 1: Client}
     */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        return [$account, $client];
    }
}
