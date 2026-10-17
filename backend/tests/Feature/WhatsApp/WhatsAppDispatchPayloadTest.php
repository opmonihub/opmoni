<?php

namespace Tests\Feature\WhatsApp;

use App\Http\Controllers\Api\V1\Tenant\WhatsAppDispatchController;
use App\Models\ClientDispatchContact;
use App\Models\WhatsAppDispatch;
use App\Services\WhatsApp\WhatsAppDispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class WhatsAppDispatchPayloadTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, bool, ?string}>
     */
    public static function retryMetadata(): array
    {
        return [
            'safe before send' => [['retry_safe' => true, 'send_attempted_at' => null], true, null],
            'uncertain after send' => [['retry_safe' => false, 'send_attempted_at' => '2026-10-15 12:00:00'], false, '2026-10-15T12:00:00+00:00'],
            'legacy missing metadata' => [[], false, null],
        ];
    }

    #[DataProvider('retryMetadata')]
    public function test_payload_exposes_only_safe_retry_metadata(array $metadata, bool $retrySafe, ?string $attemptedAt): void
    {
        $dispatch = new WhatsAppDispatch;
        $dispatch->forceFill([
            'id' => 71,
            'client_id' => 7,
            'obligation' => WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
            'artifact_type' => 'guia',
            'competence' => '2026-09',
            'slip_number' => 'DAS-1',
            'status' => WhatsAppDispatch::STATUS_FAILED,
            'failure_reason' => 'O resultado anterior é incerto.',
            'idempotency_key' => 'secret-logical-key',
            'whatsapp_id' => 'private-destination',
            'instance_api_key' => 'secret-api-key',
            'body' => 'private-document-body',
            ...$metadata,
        ]);
        $contact = new ClientDispatchContact;
        $contact->forceFill(['id' => 10, 'label' => 'Sócio', 'channel' => 'whatsapp', 'value' => '11987654321']);
        $dispatch->setRelation('dispatchContact', $contact);

        $controller = new WhatsAppDispatchController(new WhatsAppDispatchService);
        $payload = (new ReflectionMethod($controller, 'payload'))->invoke($controller, $dispatch);

        $this->assertArrayHasKey('retry_safe', $payload);
        $this->assertSame($retrySafe, $payload['retry_safe']);
        $this->assertArrayHasKey('send_attempted_at', $payload);
        $this->assertSame($attemptedAt, $payload['send_attempted_at']);
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $payload['status']);
        $this->assertSame('DAS-1', $payload['slip_number']);
        $this->assertSame(10, $payload['contact']['id']);
        $this->assertSame('O resultado anterior é incerto.', $payload['failure_reason']);
        $this->assertSame([
            'id', 'client_id', 'obligation', 'artifact_type', 'competence', 'slip_number',
            'contact', 'status', 'retry_safe', 'send_attempted_at', 'message_id', 'failure_reason',
            'dispatched_in_support', 'created_at', 'updated_at',
        ], array_keys($payload));
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['secret-logical-key', 'private-destination', 'secret-api-key', 'private-document-body'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authorizedActions(): array
    {
        return [
            'legacy dispatch' => ['store', 'create'],
            'send guides' => ['sendGuides', 'create'],
            'tracking' => ['index', 'viewAny'],
        ];
    }

    #[DataProvider('authorizedActions')]
    public function test_request_authorization_is_not_bypassed_by_retry_metadata(string $action, string $ability): void
    {
        config()->set('whatsapp.enabled', true);
        Gate::shouldReceive('authorize')->once()->with($ability, WhatsAppDispatch::class)
            ->andThrow(new AuthorizationException);

        $controller = new WhatsAppDispatchController(new WhatsAppDispatchService);
        $request = Request::create('/dispatch', 'POST', ['retry_safe' => true, 'send_attempted_at' => null]);

        $this->expectException(AuthorizationException::class);
        $controller->{$action}(WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS, 7, $request);
    }
}
