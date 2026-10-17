<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Services\WhatsApp\WzapClient;
use App\Support\ExternalHttpBudget;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Run only with the coordinator-approved isolated phpunit.pgsql.xml environment. */
class WhatsAppChannelEffectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WhatsAppConcurrencyTest::guardIsolation();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => null]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        resolve(CurrentTenant::class)->accountId = null;
        parent::tearDown();
    }

    public function test_create_key_survives_primary_rollback_and_lease_expiry_before_connect(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $clock = 0.0;
        $request = Request::create('/');
        $request->attributes->set('access.session_lock', new class
        {
            public function isOwnedByCurrentProcess(): bool
            {
                return true;
            }
        });
        $request->attributes->set('access.session_lock_acquired_at', 0.0);
        $budget = ExternalHttpBudget::fromRequest($request, function () use (&$clock): float {
            return $clock;
        });
        $factory = new ExternalEffectJournalConnection;
        $effects = new WhatsAppChannelEffects(new ExternalEffectJournal($factory), new WzapClient);
        $this->assertNotSame(DB::connection()->getPdo(), $factory->connection()->getPdo());
        Http::fake(function ($request) use (&$clock) {
            if ($request->url() === 'http://wzap.test/instances') {
                $clock = 25.0;

                return Http::response(['data' => ['id' => 'pg-inst', 'instance_api_key' => 'pg-one-time']], 201);
            }

            return Http::response(['data' => ['status' => 'pairing']]);
        });
        DB::beginTransaction();
        try {
            DB::table('accounts')->where('id', $account->id)->lockForUpdate()->first();
            DB::table('accounts')->where('id', $account->id)->update(['name' => 'rolled-back']);
            $effects->pair($account->id, 123, null, null, $budget);
            $this->fail('Must stop synchronously before connect.');
        } catch (HttpResponseException $error) {
            $this->assertSame(409, $error->getResponse()->getStatusCode());
            $body = json_decode($error->getResponse()->getContent(), true);
        } finally {
            DB::rollBack();
        }
        $this->assertNull(WhatsAppChannel::forAccount($account->id));
        $fresh = new WhatsAppChannelEffects(new ExternalEffectJournal(new ExternalEffectJournalConnection), new WzapClient);
        $result = $fresh->pair($account->id, 123, null, $body['operation_id']);
        $this->assertSame('pg-one-time', $result['channel']->instanceApiKey());
        $result['channel']->save();
        $this->assertSame('pg-one-time', WhatsAppChannel::forAccount($account->id)->instanceApiKey());
        Http::assertSentCount(2);
    }

    public function test_same_second_ordinary_write_after_unprojected_receipt_is_not_rewound(): void
    {
        $this->freezeTime();
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $channel = WhatsAppChannel::factory()->connected('pg-key')->create(['account_id' => $account->id, 'wzap_instance_id' => 'pg-inst']);
        $journal = resolve(ExternalEffectJournal::class);
        $effect = $journal->arm($journal->begin($account->id, 123, WhatsAppChannelEffects::resource($account->id), 'whatsapp.disconnect'));
        $effect = $journal->confirm($effect, 'applied', ['instance_id' => 'pg-inst', 'status' => 'disconnected']);
        $channel->forceFill(['status' => 'connected', 'connected_at' => now(), 'last_error' => null])->save();
        $this->assertTrue($channel->fresh()->updated_at->equalTo($effect['confirmed_at']));
        $this->assertSame((int) $effect['id'], $channel->fresh()->last_projected_effect_id);
        Http::fake();

        foreach (range(1, 2) as $attempt) {
            $result = resolve(WhatsAppChannelEffects::class)->continueOperation($account->id, 123, $channel->fresh(), $effect['operation_id']);
            $this->assertSame('connected', $result['channel']->status);
            $result['channel']->save();
        }
        Http::assertNothingSent();
    }

    public function test_ordinary_status_write_does_not_acknowledge_an_unprojected_key_receipt(): void
    {
        $this->freezeTime();
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $channel = WhatsAppChannel::factory()->connected('old-key')->create(['account_id' => $account->id, 'wzap_instance_id' => 'pg-inst']);
        $journal = resolve(ExternalEffectJournal::class);
        $rotate = $journal->arm($journal->begin($account->id, 123, WhatsAppChannelEffects::resource($account->id), 'whatsapp.instance.rotate'));
        $rotate = $journal->confirm($rotate, 'applied', ['instance_id' => 'pg-inst', 'instance_api_key' => 'rotated-key']);
        $connect = $journal->arm($journal->begin($account->id, 123, WhatsAppChannelEffects::resource($account->id), 'whatsapp.connect', $rotate['operation_id']));
        $journal->confirm($connect, 'applied', ['instance_id' => 'pg-inst', 'status' => 'pairing']);
        $channel->forceFill(['status' => 'connected', 'connected_at' => now()])->save();
        Http::fake();

        $recovered = resolve(WhatsAppChannelEffects::class)->recover($account->id, $channel->fresh());

        $this->assertSame('connected', $recovered->status);
        $this->assertSame('rotated-key', $recovered->instanceApiKey());
        Http::assertNothingSent();
    }

    public function test_repeated_confirmed_connect_and_disconnect_preserve_later_same_second_writes(): void
    {
        $this->freezeTime();
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $channel = WhatsAppChannel::factory()->connected('pg-key')->create(['account_id' => $account->id, 'wzap_instance_id' => 'pg-inst']);
        Http::fake(['*' => Http::response(['data' => ['status' => 'pairing']])]);
        $effects = resolve(WhatsAppChannelEffects::class);
        $paired = $effects->pair($account->id, 123, $channel);
        $paired['channel']->save();
        $channel = $channel->fresh();
        $channel->forceFill(['status' => 'connected', 'connected_at' => now(), 'pairing_started_at' => null])->save();
        foreach (range(1, 2) as $attempt) {
            $result = $effects->continueOperation($account->id, 123, $channel->fresh(), $paired['operation_id']);
            $this->assertSame('connected', $result['channel']->status);
            $result['channel']->save();
        }
        $disconnected = $effects->disconnect($account->id, 123, $channel->fresh());
        $disconnected['channel']->save();
        $channel = $channel->fresh();
        $channel->forceFill(['status' => 'connected', 'connected_at' => now()])->save();
        foreach (range(1, 2) as $attempt) {
            $result = $effects->continueOperation($account->id, 123, $channel->fresh(), $disconnected['operation_id']);
            $this->assertSame('connected', $result['channel']->status);
            $result['channel']->save();
        }
        try {
            $effects->continueOperation($account->id, 123, $channel->fresh(), $paired['operation_id']);
            $this->fail('An older operation must remain superseded.');
        } catch (HttpResponseException $error) {
            $this->assertSame('operation_superseded', json_decode($error->getResponse()->getContent(), true)['code']);
        }
        Http::assertSentCount(2);
    }

    public function test_disconnect_receipt_recovers_after_projection_rollback_without_another_post(): void
    {
        $this->freezeTime();
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $channel = WhatsAppChannel::factory()->connected('pg-key')->create(['account_id' => $account->id, 'wzap_instance_id' => 'pg-inst']);
        Http::fake(['*' => Http::response([], 204)]);
        $effects = resolve(WhatsAppChannelEffects::class);
        DB::beginTransaction();
        try {
            DB::table('accounts')->where('id', $account->id)->lockForUpdate()->first();
            $result = $effects->disconnect($account->id, 123, $channel);
            $result['channel']->save();
        } finally {
            DB::rollBack();
        }
        $this->assertSame('connected', $channel->fresh()->status);
        $this->assertSame(0, $channel->fresh()->last_projected_effect_id);
        $receipt = resolve(ExternalEffectJournal::class)->find($account->id, $result['operation_id'], WhatsAppChannelEffects::resource($account->id), 'whatsapp.disconnect');
        $this->assertTrue($channel->fresh()->updated_at->equalTo($receipt['confirmed_at']));
        $fresh = new WhatsAppChannelEffects(new ExternalEffectJournal(new ExternalEffectJournalConnection), new WzapClient);
        $recovered = $fresh->continueOperation($account->id, 123, $channel->fresh(), $result['operation_id']);
        $this->assertSame('disconnected', $recovered['channel']->status);
        $recovered['channel']->save();
        $this->assertSame('disconnected', $channel->fresh()->status);
        Http::assertSentCount(1);
    }
}
