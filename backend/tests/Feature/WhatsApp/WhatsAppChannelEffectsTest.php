<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Services\WhatsApp\WzapClient;
use App\Support\ExternalHttpBudget;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppChannelEffectsTest extends TestCase
{
    protected ExternalEffectJournal $journal;

    protected WhatsAppChannelEffects $effects;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => null]);
        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection->getSchemaBuilder()->create('external_effect_journal', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('actor_id');
            foreach (['operation_id', 'resource_hash', 'effect_key', 'step', 'state', 'owner_token'] as $name) {
                $table->string($name);
            }
            $table->string('receipt')->nullable();
            $table->integer('version');
            $table->text('payload_encrypted')->nullable();
            $table->timestamp('armed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('effect_key');
        });
        $this->journal = new ExternalEffectJournal(new ExternalEffectJournalConnection(fn () => $connection));
        $this->effects = new WhatsAppChannelEffects($this->journal, new WzapClient);
    }

    public function test_one_time_key_is_confirmed_before_budget_expires_and_continuation_never_recreates(): void
    {
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
        Http::fake(function ($request) use (&$clock) {
            if ($request->url() === 'http://wzap.test/instances') {
                $clock = 25.0;

                return Http::response(['data' => ['id' => 'inst-1', 'instance_api_key' => 'one-time-secret']], 201);
            }

            return Http::response(['data' => ['status' => 'pairing']]);
        });
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, null, null, $budget));
        $this->assertSame('operation_continuation_required', $body['code']);
        $this->assertSame('whatsapp.connect', $body['next_step']);
        $rows = $this->journal->forResource(7, WhatsAppChannelEffects::resource(7));
        $this->assertSame('one-time-secret', $rows[0]['payload']['instance_api_key']);
        $result = $this->effects->pair(7, 8, null, $body['operation_id']);
        $this->assertSame('one-time-secret', $result['channel']->instanceApiKey());
        $this->assertSame('pairing', $result['gateway']['status']);
        Http::assertSentCount(2);
    }

    public function test_uncertain_creation_is_not_retried_or_rotated(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, null));
        $this->assertSame('operation_manual_review_required', $body['code']);
        $this->conflict(fn () => $this->effects->pair(7, 8, null, $body['operation_id']));
        Http::assertSentCount(1);
    }

    public function test_confirmed_disconnect_recovers_without_another_post_and_old_pairing_cannot_resume(): void
    {
        $channel = new WhatsAppChannel(['wzap_instance_id' => 'inst-1', 'status' => 'connected']);
        $channel->forceFill(['instance_api_key_encrypted' => encrypt('key', false)]);
        Http::fake(['*' => Http::response([], 204)]);
        $result = $this->effects->disconnect(7, 8, $channel);
        $recovered = $this->effects->recover(7, null);
        $this->assertSame('disconnected', $recovered->status);
        $this->effects->disconnect(7, 8, $recovered, $result['operation_id']);
        Http::assertSentCount(1);
    }

    public function test_operation_from_another_account_is_not_imported(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, null));
        try {
            $this->effects->pair(9, 8, null, $body['operation_id']);
            $this->fail('Cross-account continuation must be denied.');
        } catch (HttpResponseException $error) {
            $this->assertSame(404, $error->getResponse()->getStatusCode());
        }
        Http::assertSentCount(1);
    }

    public function test_uncertain_connect_reconciles_only_via_get_status(): void
    {
        $resource = WhatsAppChannelEffects::resource(7);
        $create = $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.instance.create'));
        $create = $this->journal->confirm($create, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'key']);
        $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.connect', $create['operation_id']));
        Http::fake(['*' => Http::response(['data' => ['status' => 'connected']])]);
        $result = $this->effects->reconcile(7, 8, null, $create['operation_id']);
        $this->assertSame('connected', $result['gateway']['status']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/status'));
        Http::assertSentCount(1);
    }

    public function test_rotation_receipt_survives_budget_loss_and_continuation_does_not_rotate(): void
    {
        $channel = new WhatsAppChannel(['wzap_instance_id' => 'inst-1', 'status' => 'disconnected']);
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
        Http::fake(function ($request) use (&$clock) {
            if (str_ends_with($request->url(), '/rotate')) {
                $clock = 25.0;

                return Http::response(['data' => ['instance_api_key' => 'rotated-once']]);
            }

            return Http::response(['data' => ['status' => 'connected']]);
        });
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, $channel, null, $budget));
        $result = $this->effects->pair(7, 8, $channel, $body['operation_id']);
        $this->assertSame('rotated-once', $result['channel']->instanceApiKey());
        Http::assertSentCount(2);
    }

    public function test_create_conflict_is_not_applied_and_discovery_has_no_hidden_effect(): void
    {
        Http::fake(function ($request) {
            return match (true) {
                $request->method() === 'POST' && $request->url() === 'http://wzap.test/instances' => Http::response(['error' => ['code' => 'conflict']], 409),
                $request->method() === 'GET' => Http::response(['data' => ['items' => [['id' => 'inst-1', 'external_ref' => '7']], 'next_cursor' => null]]),
                str_ends_with($request->url(), '/rotate') => Http::response(['data' => ['instance_api_key' => 'rotated-key']]),
                default => Http::response(['data' => ['status' => 'connected']]),
            };
        });
        $result = $this->effects->pair(7, 8, null);
        $rows = $this->journal->forResource(7, WhatsAppChannelEffects::resource(7));
        $this->assertSame('not_applied', $rows[0]['receipt']);
        $this->assertSame(['http_status' => 409], $rows[0]['payload']);
        $this->assertSame('rotated-key', $rows[1]['payload']['instance_api_key']);
        $this->effects->pair(7, 8, null, $result['operation_id']);
        Http::assertSentCount(4);
    }

    public function test_newer_uncertain_rotation_never_restores_old_key_or_replays_old_connect(): void
    {
        $resource = WhatsAppChannelEffects::resource(7);
        $old = $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.instance.create'));
        $old = $this->journal->confirm($old, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'old-key']);
        $connect = $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.connect', $old['operation_id']));
        $this->journal->confirm($connect, 'applied', ['instance_id' => 'inst-1', 'status' => 'connected']);
        $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.instance.rotate'));
        $recovered = $this->effects->recover(7, null);
        $this->assertNull($recovered->instanceApiKey());
        $this->assertSame('error', $recovered->status);
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, null, $old['operation_id']));
        $this->assertSame('operation_superseded', $body['code']);
        Http::assertNothingSent();
    }

    public function test_webhook_has_its_own_receipt_and_is_never_hidden_in_discovery(): void
    {
        config(['whatsapp.wzap.webhook_url' => 'https://opmoni.test/api/v1/webhooks/wzap']);
        $channel = new WhatsAppChannel(['wzap_instance_id' => 'inst-1', 'status' => 'disconnected']);
        $channel->forceFill(['instance_api_key_encrypted' => encrypt('key', false)]);
        Http::fake(fn ($request) => Http::response(['data' => $request->method() === 'PATCH' ? ['id' => 'inst-1'] : ['status' => 'connected']]));
        $result = $this->effects->pair(7, 8, $channel);
        $rows = $this->journal->forResource(7, WhatsAppChannelEffects::resource(7));
        $this->assertSame('whatsapp.webhook', $rows[0]['step']);
        $this->assertSame('confirmed', $rows[0]['state']);
        $this->effects->pair(7, 8, $channel, $result['operation_id']);
        Http::assertSentCount(2);
    }

    public function test_pending_step_cannot_be_bypassed_by_starting_another_operation(): void
    {
        $effect = $this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.instance.create');
        $body = $this->conflict(fn () => $this->effects->pair(7, 8, null));
        $this->assertSame($effect['operation_id'], $body['operation_id']);
        Http::assertNothingSent();
    }

    public function test_newer_gateway_mirror_is_not_rewound_to_old_pairing_receipt(): void
    {
        $resource = WhatsAppChannelEffects::resource(7);
        $effect = $this->journal->arm($this->journal->begin(7, 8, $resource, 'whatsapp.connect'));
        $this->journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'status' => 'pairing']);
        $channel = new WhatsAppChannel(['wzap_instance_id' => 'inst-1', 'status' => 'connected']);
        $channel->forceFill(['updated_at' => now()->addSeconds(2)]);
        $this->assertSame('connected', $this->effects->recover(7, $channel)->status);
    }

    protected function conflict(\Closure $action): array
    {
        try {
            $action();
            $this->fail('Expected an explicit synchronous conflict.');
        } catch (HttpResponseException $error) {
            $this->assertSame(409, $error->getResponse()->getStatusCode());

            return json_decode($error->getResponse()->getContent(), true);
        }
    }
}
