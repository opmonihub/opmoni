<?php

namespace Tests\Feature;

use App\Services\SerproPgdasHomologationProbe;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Probe PGDAS contra homologação real — opt-in (`serpro-trial`).
 */
#[Group('serpro-trial')]
class SerproPgdasHomologationProbeTest extends TestCase
{
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

        $report = resolve(SerproPgdasHomologationProbe::class)->run(null);

        if ($report['outcome'] === SerproPgdasHomologationProbe::OUTCOME_SKIP) {
            $detail = json_encode($report['steps'], JSON_UNESCAPED_UNICODE);
            $this->markTestSkipped('Pré-requisito ou cota: '.$detail);
        }

        $this->assertSame(SerproPgdasHomologationProbe::OUTCOME_PASS, $report['outcome']);
        $this->assertArrayHasKey('projection', $report);
    }
}
