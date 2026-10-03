<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\ManifestacaoDispatcher;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ManifestacaoGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifestacao_enabled_nasce_desligada(): void
    {
        $this->assertFalse(config('fiscal.manifestacao_enabled'));
    }

    public function test_com_gate_desligado_nada_e_enfileirado_nem_enviado(): void
    {
        config(['fiscal.manifestacao_enabled' => false]);

        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $chave = str_repeat('3', 44);

        Bus::fake();

        $dispatcher = resolve(ManifestacaoDispatcher::class);

        $this->assertFalse($dispatcher->manifestacaoHabilitada());
        $this->assertFalse($dispatcher->enfileirarCiencia($account->getKey(), $client, $chave));

        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
        $this->assertDatabaseCount('fiscal_manifestations', 0);

        config(['fiscal.manifestacao_enabled' => true]);

        resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: $account->getKey(),
            clientId: $client->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        config(['fiscal.manifestacao_enabled' => false]);

        $job = new SendFiscalManifestationJob(
            accountId: $account->getKey(),
            clientId: $client->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        $registro = FiscalManifestation::withoutGlobalScope('account')->firstOrFail();

        $this->assertNull($registro->sent_at);
    }

    public function test_com_gate_ligado_o_despacho_enfileira(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $chave = str_repeat('4', 44);

        Bus::fake();

        $this->assertTrue(resolve(ManifestacaoDispatcher::class)->enfileirarCiencia($account->getKey(), $client, $chave));

        Bus::assertDispatched(SendFiscalManifestationJob::class, fn (SendFiscalManifestationJob $job): bool => $job->accountId === $account->getKey()
            && $job->clientId === $client->getKey()
            && $job->chaveAcesso === $chave
            && $job->eventType === FiscalManifestationEventType::CienciaEmissao);
    }
}
