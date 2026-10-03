<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SerproConnection;
use App\Services\SerproPgdasHomologationProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Probe PGDAS contra homologação real — opt-in (`serpro-trial`).
 */
#[Group('serpro-trial')]
class SerproPgdasHomologationProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fiscal.environment' => 'homologacao',
            'serpro_probes.enabled' => true,
            'serpro_probes.homologation_canary_cnpj' => '30288513000100',
            'serpro_probes.homologation_account_cnpj' => '48123272000105',
        ]);
    }

    public function test_sem_credencial_da_skip_sem_http(): void
    {
        Http::preventStrayRequests();

        $report = resolve(SerproPgdasHomologationProbe::class)->run(null);

        $this->assertSame(SerproPgdasHomologationProbe::OUTCOME_SKIP, $report['outcome']);
        Http::assertNothingSent();
    }

    public function test_recusa_sem_homologacao_e_sem_http(): void
    {
        config(['fiscal.environment' => 'producao']);
        config(['serpro_probes.enabled' => true]);

        Http::preventStrayRequests();

        $report = resolve(SerproPgdasHomologationProbe::class)->run(null);

        $this->assertSame(SerproPgdasHomologationProbe::OUTCOME_FAIL, $report['outcome']);
        Http::assertNothingSent();
    }

    public function test_probe_real_auto_center_quando_pre_requisitos_existem(): void
    {
        if (config('fiscal.environment') !== 'homologacao') {
            $this->markTestSkipped('FISCAL_ENVIRONMENT não é homologacao.');
        }

        if (! config('serpro_probes.enabled', false)) {
            $this->markTestSkipped('SERPRO_PROBE_ENABLED não está ligado.');
        }

        $connection = SerproConnection::current();
        if ($connection === null || ! $connection->isConfigured()) {
            $this->markTestSkipped('SerproConnection real ausente ou incompleta (seed/.env homolog).');
        }

        $canary = (string) config('serpro_probes.homologation_canary_cnpj');
        $client = Client::query()->where('tax_id', $canary)->first();
        if ($client === null) {
            $this->markTestSkipped("Cliente canário {$canary} não encontrado neste banco.");
        }

        $report = resolve(SerproPgdasHomologationProbe::class)->run(
            $client->account_id,
            $canary,
        );

        if ($report['outcome'] === SerproPgdasHomologationProbe::OUTCOME_SKIP) {
            $detail = json_encode($report['steps'], JSON_UNESCAPED_UNICODE);
            $this->markTestSkipped('Pré-requisito ou cota: '.$detail);
        }

        $this->assertSame(SerproPgdasHomologationProbe::OUTCOME_PASS, $report['outcome']);
        $this->assertArrayHasKey('projection', $report);
    }
}
