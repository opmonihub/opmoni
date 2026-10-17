<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

class WhatsAppDispatchTransactionTest extends BusinessTestCase
{
    use RefreshDatabase;

    /**
     * Estes testes precisam de commits reais, sem a transação externa do trait.
     */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            DB::connection()->getSchemaBuilder()->dropAllTables();
            RefreshDatabaseState::$migrated = false;
        });
    }

    private Client $client;

    private User $admin;

    /** @var list<int> */
    private array $enqueueTransactionLevels = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('whatsapp.enabled', true);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.after_commit', false);

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $this->client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $account->getKey(),
            'client_id' => $this->client->getKey(),
            'value' => '5511987654321',
        ]);
        WhatsAppChannel::factory()->connected('transaction-test')->create(['account_id' => $account->getKey()]);
        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $this->client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => [['period' => '2026-01', 'slip_number' => '07202215764027873']],
        ]);
        $this->admin = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $this->admin->getKey(), 'role' => 'admin']);
        $this->selectBusinessAccount($this->admin, $account->getKey());
        $this->actingAs($this->admin);

        $this->app['events']->listen(JobQueued::class, function (JobQueued $event): void {
            if ($event->job instanceof SendWhatsAppDocumentJob) {
                $this->enqueueTransactionLevels[] = DB::transactionLevel();
            }
        });
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_post_so_publica_job_depois_do_commit_externo(): void
    {
        DB::beginTransaction();
        try {
            $response = $this->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
                ->assertAccepted();

            $this->assertSame(1, WhatsAppDispatch::count());
            $this->assertSame([], $this->enqueueTransactionLevels);
            $this->assertSame(0, DB::table('jobs')->count());

            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame([0], $this->enqueueTransactionLevels);
        $this->assertSame(1, DB::table('jobs')->count());
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $command = unserialize($job->payload()['data']['command']);
        $this->assertInstanceOf(SendWhatsAppDocumentJob::class, $command);
        $this->assertSame($response->json('data.id'), $command->dispatchId);
        $this->assertNotNull(WhatsAppDispatch::find($command->dispatchId));
        $job->delete();
    }

    public function test_rollback_do_post_nao_publica_job_nem_preserva_disparo(): void
    {
        DB::beginTransaction();
        try {
            $this->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
                ->assertAccepted();
        } finally {
            DB::rollBack();
        }

        $this->assertSame([], $this->enqueueTransactionLevels);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, WhatsAppDispatch::count());
    }

    private function url(): string
    {
        return "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatch";
    }
}
