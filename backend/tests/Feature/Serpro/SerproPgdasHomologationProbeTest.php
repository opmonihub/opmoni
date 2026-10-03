<?php

namespace Tests\Feature\Serpro;

use App\Models\Client;
use App\Models\SerproConnection;
use App\Services\SerproPgdasHomologationProbe;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Probe PGDAS contra homologação **real** (grupo `serpro-trial`). Só executa
 * quando `SERPRO_PROBE_REAL=1` e já existe `SerproConnection` configurada no
 * banco — fora do fluxo `RefreshDatabase` da suíte padrão. Pass/skip/throttle
 * ficam em `SerproPgdasHomologationProbeHttpTest`.
 */
#[Group('serpro-trial')]
class SerproPgdasHomologationProbeTest extends TestCase
{
    public function test_homologacao_real_ou_skip_justificado(): void
    {
        if (filter_var(env('SERPRO_PROBE_REAL', false), FILTER_VALIDATE_BOOLEAN) !== true) {
            $this->markTestSkipped('Defina SERPRO_PROBE_REAL=1 para chamar homologação real (fora do CI).');
        }

        if (config('fiscal.environment') !== 'homologacao') {
            $this->markTestSkipped('FISCAL_ENVIRONMENT não é homologacao.');
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

        $this->assertContains($report['outcome'], ['pass', 'skip'], json_encode($report, JSON_THROW_ON_ERROR));

        if ($report['outcome'] === 'pass') {
            $this->assertArrayHasKey('projecao', $report['steps']);
            $this->assertArrayHasKey('projection', $report);
        }
    }
}
