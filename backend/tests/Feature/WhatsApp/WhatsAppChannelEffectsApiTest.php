<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

class WhatsAppChannelEffectsApiTest extends BusinessTestCase
{
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        config(['whatsapp.enabled' => true, 'whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => null]);
        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection->getPdo()->exec(DB::connection()->selectOne("SELECT sql FROM sqlite_master WHERE name = 'external_effect_journal'")->sql);
        $this->app->instance(ExternalEffectJournalConnection::class, new ExternalEffectJournalConnection(fn () => $connection));
        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->id;
    }

    public function test_pairing_and_disconnect_keep_existing_contract_without_exposing_key(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => ['id' => 'inst-1', 'instance_api_key' => 'one-time-secret']], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['status' => 'pairing', 'qr_code' => '2@qr', 'qr_expires_at' => '2026-10-16T10:00:00Z']]),
            'wzap.test/instances/inst-1/disconnect' => Http::response([], 204),
        ]);
        $this->actingAs($this->member('admin'));
        $response = $this->postJson('/api/v1/whatsapp/channel/pairing')->assertOk()->assertJsonPath('data.status', 'pairing');
        $this->assertStringNotContainsString('one-time-secret', $response->getContent());
        $this->assertSame('one-time-secret', WhatsAppChannel::forAccount($this->account->id)->instanceApiKey());
        $response = $this->deleteJson('/api/v1/whatsapp/channel')->assertOk()->assertJsonPath('data.status', 'disconnected');
        $operation = $response->json('operation_id');
        $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/continue")->assertOk()->assertJsonPath('data.status', 'disconnected');
        Http::assertSentCount(3);
    }

    public function test_lost_mirror_is_recovered_for_reader_without_persistence(): void
    {
        $journal = resolve(ExternalEffectJournal::class);
        $effect = $journal->arm($journal->begin($this->account->id, 1, WhatsAppChannelEffects::resource($this->account->id), 'whatsapp.instance.create'));
        $effect = $journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'recovered-key']);
        $connected = $journal->arm($journal->begin($this->account->id, 1, WhatsAppChannelEffects::resource($this->account->id), 'whatsapp.connect', $effect['operation_id']));
        $journal->confirm($connected, 'applied', ['instance_id' => 'inst-1', 'status' => 'connected']);
        Http::fake(['*' => Http::response(['data' => ['status' => 'connected']])]);
        $response = $this->actingAs($this->member('user'))->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.operation', null);
        $this->assertStringNotContainsString('recovered-key', $response->getContent());
        $this->assertStringNotContainsString('instance_api_key', $response->getContent());
        $this->assertNull(WhatsAppChannel::forAccount($this->account->id));
        $this->actingAs($this->member('admin'))->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.operation', null);
        $this->assertSame('recovered-key', WhatsAppChannel::forAccount($this->account->id)->instanceApiKey());
    }

    public function test_confirmed_creation_without_connect_exposes_continuation_without_provider_calls(): void
    {
        $journal = resolve(ExternalEffectJournal::class);
        $effect = $journal->arm($journal->begin($this->account->id, 1, WhatsAppChannelEffects::resource($this->account->id), 'whatsapp.instance.create'));
        $journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'incomplete-secret']);
        foreach (['user', 'admin'] as $role) {
            $response = $this->actingAs($this->member($role))->getJson('/api/v1/whatsapp/channel')->assertOk()
                ->assertJsonPath('data.status', 'disconnected')
                ->assertJsonPath('data.operation.code', 'operation_continuation_required')
                ->assertJsonPath('data.operation.operation_id', $effect['operation_id'])
                ->assertJsonPath('data.operation.next_step', 'whatsapp.connect');
            $this->assertStringNotContainsString('incomplete-secret', $response->getContent());
            $this->assertStringNotContainsString('instance_api_key', $response->getContent());
            if ($role === 'user') {
                $this->assertNull(WhatsAppChannel::forAccount($this->account->id));
            }
        }
        $this->assertSame('incomplete-secret', WhatsAppChannel::forAccount($this->account->id)->instanceApiKey());
        Http::assertNothingSent();
    }

    public function test_new_routes_enforce_role_and_account_and_csrf(): void
    {
        $journal = resolve(ExternalEffectJournal::class);
        $effect = $journal->begin($this->account->id, 1, WhatsAppChannelEffects::resource($this->account->id), 'whatsapp.connect');
        $operation = $effect['operation_id'];
        $this->actingAs($this->member('user'));
        foreach (['continue', 'reconcile'] as $action) {
            $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/{$action}")->assertForbidden();
        }
        $this->actingAs($this->member('admin'))->withHeader('X-CSRF-Token', 'wrong');
        $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/continue")->assertForbidden();
        $other = Account::factory()->create();
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $other->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->selectBusinessAccount($user, $other->id);
        $this->actingAs($user);
        $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/continue")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unavailable_journal_blocks_provider_before_any_effect(): void
    {
        $this->app->instance(ExternalEffectJournalConnection::class, new ExternalEffectJournalConnection);
        $this->actingAs($this->member('admin'))->postJson('/api/v1/whatsapp/channel/pairing')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_reload_exposes_pending_operation_without_sending_provider_request(): void
    {
        $journal = resolve(ExternalEffectJournal::class);
        $effect = $journal->arm($journal->begin($this->account->id, 1, WhatsAppChannelEffects::resource($this->account->id), 'whatsapp.instance.create'));
        $this->actingAs($this->member('admin'))->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.operation.code', 'operation_manual_review_required')
            ->assertJsonPath('data.operation.operation_id', $effect['operation_id']);
        $this->assertNull(WhatsAppChannel::forAccount($this->account->id));
        Http::assertNothingSent();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $this->account->id, 'user_id' => $user->id, 'role' => $role]);
        $this->selectBusinessAccount($user, $this->account->id);

        return $user;
    }
}
