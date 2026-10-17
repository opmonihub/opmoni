<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Services\WhatsApp\WzapException;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WhatsAppChannelEffectsRegressionTest extends TestCase
{
    private ExternalEffectJournal $journal;

    private WhatsAppChannelEffects $effects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(18, 0));
        Http::preventStrayRequests();
        config([
            'whatsapp.wzap.base_url' => 'http://wzap.test',
            'whatsapp.wzap.api_key' => 'global',
            'whatsapp.wzap.webhook_url' => null,
        ]);

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::connection()->beginTransaction();

        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection->getSchemaBuilder()->create('external_effect_journal', function (Blueprint $table): void {
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

        $connections = new ExternalEffectJournalConnection(fn () => $connection);
        $this->app->instance(ExternalEffectJournalConnection::class, $connections);
        $this->assertNotSame(DB::connection()->getPdo(), $connections->connection()->getPdo());
        $this->assertSame(0, $connections->connection()->transactionLevel());
        $this->assertFalse($connections->connection()->getPdo()->inTransaction());

        $this->journal = resolve(ExternalEffectJournal::class);
        $this->effects = resolve(WhatsAppChannelEffects::class);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection()->rollBack();
            $this->travelBack();
        } finally {
            parent::tearDown();
        }
    }

    public function test_continuing_confirmed_pairing_preserves_newer_connected_mirror_without_http(): void
    {
        $create = $this->confirmedCreation();
        $connect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.connect', $create['operation_id']));
        $connect = $this->journal->confirm($connect, 'applied', ['instance_id' => 'inst-1', 'status' => 'pairing']);
        $channel = $this->connectedMirror();
        $channel->forceFill(['updated_at' => now()->addSecond()]);
        $this->assertTrue($channel->updated_at->greaterThan($connect['confirmed_at']));
        Http::fake();

        $result = $this->effects->continueOperation(7, 8, $channel, $create['operation_id']);

        Http::assertNothingSent();
        $this->assertSame($create['operation_id'], $result['operation_id']);
        $this->assertSame('connected', $result['channel']->status);
        $this->assertSame('connected', $result['gateway']['status']);
        $this->assertSame('pairing', $this->journal->find(7, $create['operation_id'], WhatsAppChannelEffects::resource(7), 'whatsapp.connect')['payload']['status']);
    }

    public function test_continuing_confirmed_disconnect_preserves_newer_connected_mirror_without_http(): void
    {
        $effect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.disconnect'));
        $effect = $this->journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'status' => 'disconnected']);
        $channel = $this->connectedMirror();
        $channel->forceFill(['updated_at' => now()->addSecond()]);
        Http::fake();

        $result = $this->effects->continueOperation(7, 8, $channel, $effect['operation_id']);

        Http::assertNothingSent();
        $this->assertSame('connected', $result['channel']->status);
        $this->assertSame('connected', $result['gateway']['status']);
    }

    public function test_recovery_preserves_connected_mirror_when_receipt_has_the_same_second_timestamp(): void
    {
        $connect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.connect'));
        $connect = $this->journal->confirm($connect, 'applied', ['instance_id' => 'inst-1', 'status' => 'pairing']);
        $channel = $this->connectedMirror();
        $channel->forceFill(['updated_at' => $connect['confirmed_at']]);
        $this->assertSame($connect['confirmed_at'], $channel->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('000000', $channel->updated_at->format('u'));
        Http::fake();

        $recovered = $this->effects->recover(7, $channel);

        Http::assertNothingSent();
        $this->assertSame('connected', $recovered->status);
        $this->assertSame('2026-10-15 18:00:00', $recovered->connected_at->format('Y-m-d H:i:s'));
    }

    public function test_unprojected_same_second_disconnect_is_recovered_with_a_causal_watermark(): void
    {
        $channel = $this->connectedMirror()->forceFill(['updated_at' => now(), 'last_projected_effect_id' => 0]);
        $effect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.disconnect'));
        $effect = $this->journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'status' => 'disconnected']);
        Http::fake();

        $result = $this->effects->continueOperation(7, 8, $channel, $effect['operation_id']);

        $this->assertSame('disconnected', $result['channel']->status);
        $this->assertSame((int) $effect['id'], $result['channel']->last_projected_effect_id);
        Http::assertNothingSent();
    }

    public function test_receipt_watermark_preserves_same_second_newer_status_and_key(): void
    {
        $create = $this->confirmedCreation();
        $connect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.connect', $create['operation_id']));
        $connect = $this->journal->confirm($connect, 'applied', ['instance_id' => 'inst-1', 'status' => 'pairing']);
        $channel = $this->connectedMirror()->forceFill([
            'updated_at' => now(), 'last_projected_effect_id' => (int) $connect['id'],
            'last_projected_credential_effect_id' => (int) $create['id'],
            'instance_api_key_encrypted' => Crypt::encryptString('newer-key'),
        ]);
        Http::fake();

        foreach (range(1, 2) as $attempt) {
            $result = $this->effects->continueOperation(7, 8, $channel, $create['operation_id']);
            $channel = $result['channel'];
            $this->assertSame('connected', $channel->status);
            $this->assertSame('newer-key', $channel->instanceApiKey());
        }
        $this->assertArrayNotHasKey('last_projected_effect_id', $channel->toArray());
        $this->assertArrayNotHasKey('last_projected_credential_effect_id', $channel->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('pendingSteps')]
    public function test_reconciliation_of_pending_step_requires_continuation_and_never_sends_http(string $step): void
    {
        $effect = $this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), $step);
        $channel = $step === 'whatsapp.disconnect' ? $this->connectedMirror() : null;
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => ['instance' => ['id' => 'inst-1'], 'instance_api_key' => 'key']], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => ['status' => 'pairing']]]),
            'wzap.test/instances/inst-1/disconnect' => Http::response([], 204),
        ]);

        $response = $this->operationResponse(fn () => $this->effects->reconcile(7, 8, $channel, $effect['operation_id']));

        Http::assertNothingSent();
        $this->assertOperationConflict($response, 'operation_continuation_required', $effect['operation_id'], $step);
        $this->assertSame($effect, $this->journal->find(7, $effect['operation_id'], WhatsAppChannelEffects::resource(7), $step));
        $this->assertCount(1, $this->journal->forResource(7, WhatsAppChannelEffects::resource(7)));
    }

    /** @return array<string, array{string}> */
    public static function pendingSteps(): array
    {
        return [
            'pending creation' => ['whatsapp.instance.create'],
            'pending disconnect' => ['whatsapp.disconnect'],
        ];
    }

    /** @param array<string, mixed> $providerResponse */
    #[DataProvider('unprovenStatuses')]
    public function test_uncertain_connect_with_unproven_status_requires_manual_review_instead_of_generic_503(array $providerResponse): void
    {
        $create = $this->confirmedCreation();
        $connect = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.connect', $create['operation_id']));
        Http::fake(['wzap.test/instances/inst-1/status' => Http::response($providerResponse)]);

        $response = $this->operationResponse(fn () => $this->effects->reconcile(7, 8, null, $create['operation_id']));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->url() === 'http://wzap.test/instances/inst-1/status');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
        $this->assertSame($connect, $this->journal->find(7, $create['operation_id'], WhatsAppChannelEffects::resource(7), 'whatsapp.connect'));
        $this->assertSame('uncertain', $connect['state']);
        $this->assertOperationConflict($response, 'operation_manual_review_required', $create['operation_id'], 'whatsapp.connect');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unprovenStatuses(): array
    {
        return [
            'unknown status' => [['data' => ['connection' => ['status' => 'unknown']]]],
            'missing status' => [['data' => ['connection' => []]]],
        ];
    }

    /** @return array<string, mixed> */
    private function confirmedCreation(): array
    {
        $create = $this->journal->arm($this->journal->begin(7, 8, WhatsAppChannelEffects::resource(7), 'whatsapp.instance.create'));

        return $this->journal->confirm($create, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'key']);
    }

    private function connectedMirror(): WhatsAppChannel
    {
        $channel = new WhatsAppChannel(['wzap_instance_id' => 'inst-1', 'status' => 'connected', 'connected_at' => now()]);

        return $channel->forceFill(['account_id' => 7, 'instance_api_key_encrypted' => Crypt::encryptString('key')]);
    }

    /** @return array<string, mixed>|Response */
    private function operationResponse(Closure $action): array|Response
    {
        try {
            return $action();
        } catch (HttpResponseException $error) {
            return $error->getResponse();
        } catch (WzapException $error) {
            return resolve(ExceptionHandler::class)->render(Request::create('/api/v1/whatsapp/channel/operations/reconcile', 'POST'), $error);
        }
    }

    /** @param array<string, mixed>|Response $response */
    private function assertOperationConflict(array|Response $response, string $code, string $operationId, string $step): void
    {
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(409, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($code, $body['code']);
        $this->assertTrue(Str::isUuid($body['operation_id']));
        $this->assertSame($operationId, $body['operation_id']);
        $this->assertSame($step, $body['next_step']);
    }
}
