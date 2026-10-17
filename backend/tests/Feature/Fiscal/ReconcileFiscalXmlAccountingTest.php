<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Support\FiscalEnvironment;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

class ReconcileFiscalXmlAccountingTest extends BusinessTestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261000000000000191550010000000011123456780';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03T12:00:00-03:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_nao_altera_linhas_nem_despacha(): void
    {
        [$account, $client, $summary] = $this->cenarioElegivel();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
        ])->assertSuccessful();

        $summary->refresh();
        $this->assertNull($summary->processing_state);
        Bus::assertNothingDispatched();
    }

    public function test_previsualizacao_historica_preserva_nsu_e_chave_com_gate_desligado(): void
    {
        [$account, $client, $summary] = $this->cenarioElegivel();
        config([
            'fiscal.manifestacao_enabled' => false,
            'fiscal.manifestacao_rollout_mode' => 'disabled',
        ]);
        $summary->forceFill(['nsu' => 4242])->saveQuietly();
        $nsu = $summary->nsu;
        $chave = $summary->chave_acesso;

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--client' => (string) $client->getKey(),
        ])->assertSuccessful();

        $summary->refresh();
        $this->assertSame($nsu, $summary->nsu);
        $this->assertSame($chave, $summary->chave_acesso);
        $this->assertNull($summary->processing_state);
        Bus::assertNothingDispatched();
    }

    public function test_repeticao_preserva_veredito_de_manifestacao_e_nao_duplica_job(): void
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'fleet',
        ]);

        [$account, $client, $summary] = $this->cenarioElegivel();

        $registro = resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $account->getKey(),
            clientId: (int) $client->getKey(),
            chaveAcesso: self::CHAVE,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );
        $registro->forceFill([
            'outcome' => FiscalManifestationOutcome::Sent,
            'result_code' => '135',
            'result_message' => 'Evento registrado',
        ])->save();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        $registro->refresh();
        $this->assertSame(FiscalManifestationOutcome::Sent, $registro->outcome);
        $this->assertSame('135', $registro->result_code);
        $this->assertSame(1, FiscalManifestation::withoutGlobalScope('account')->count());
        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
    }

    public function test_resumo_completo_nao_e_rebaixado(): void
    {
        [$account, $client, $summary] = $this->cenarioElegivel();
        $summary->forceFill([
            'processing_state' => FiscalProcessingState::Complete,
            'processing_reason' => FiscalProcessingReason::Ready,
            'processing_completed_at' => now(),
        ])->saveQuietly();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        $summary->refresh();
        $this->assertSame(FiscalProcessingState::Complete, $summary->processing_state);
        $this->assertSame(FiscalProcessingReason::Ready, $summary->processing_reason);
    }

    public function test_prazo_expirado_classifica_atencao_sem_enfileirar(): void
    {
        [$account, $client, $summary] = $this->cenarioElegivel();
        $summary->forceFill(['authorized_at' => now()->subDays(11)])->saveQuietly();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        $summary->refresh();
        $this->assertSame(FiscalProcessingState::Attention, $summary->processing_state);
        $this->assertSame(FiscalProcessingReason::ScienceDeadlineExpired, $summary->processing_reason);
        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
    }

    public function test_ciencia_aceita_anterior_impede_nova_ciencia_mas_mantem_estado(): void
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'fleet',
        ]);

        [$account, $client, $summary] = $this->cenarioElegivel();

        resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $account->getKey(),
            clientId: (int) $client->getKey(),
            chaveAcesso: self::CHAVE,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        )->forceFill(['outcome' => FiscalManifestationOutcome::AlreadyManifested])->save();

        $this->artisan('fiscal:reconcile-xml-accounting', [
            '--account' => (string) $account->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
    }

    /**
     * @return array{0: Account, 1: Client, 2: FiscalDocument}
     */
    private function cenarioElegivel(): array
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'fleet',
        ]);

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        $summary = FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'stage' => FiscalStage::Summary,
            'chave_acesso' => self::CHAVE,
            'authorized_at' => now()->subDay(),
            'delivery_origin' => FiscalDeliveryOrigin::AuthenticatedDistribution,
            'delivery_environment' => FiscalEnvironment::selecionar(),
            'queried_tax_id' => '00000000000191',
            'emitente_cnpj' => '99999999999999',
        ]);

        return [$account, $client, $summary];
    }
}
