<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\FiscalScienceRolloutGate;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

class FiscalScienceLiveCanaryReadinessTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_live_canary_unavailable_keeps_wider_activation_blocked(): void
    {
        config([
            'fiscal.manifestacao_enabled' => false,
            'fiscal.manifestacao_rollout_mode' => 'disabled',
            'fiscal.manifestacao_pilot_allowlist' => [],
        ]);

        $gate = resolve(FiscalScienceRolloutGate::class);
        $result = $gate->evaluate(1, 2, str_repeat('7', 44));

        $this->assertFalse($result['allowed']);
        $this->assertSame('gate_disabled', $result['reason']);
    }

    public function test_pilot_tuple_blocks_work_outside_scope_at_execution(): void
    {
        $chave = str_repeat('8', 44);

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
        ]);

        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'pilot',
            'fiscal.manifestacao_pilot_allowlist' => [
                [(int) $account->getKey(), (int) $client->getKey(), $chave],
            ],
        ]);

        resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $account->getKey(),
            clientId: (int) $client->getKey(),
            chaveAcesso: str_repeat('9', 44),
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        $job = new SendFiscalManifestationJob(
            accountId: (int) $account->getKey(),
            clientId: (int) $client->getKey(),
            chaveAcesso: str_repeat('9', 44),
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
            resolve(FiscalScienceRolloutGate::class),
        );

        Http::assertNothingSent();
    }
}
