<?php

namespace Tests\Unit;

use App\Services\SerproProbeGate;
use Tests\TestCase;

class SerproProbeGateTest extends TestCase
{
    public function test_recusa_quando_ambiente_fiscal_e_producao(): void
    {
        config(['fiscal.environment' => 'producao']);
        config(['serpro_probes.enabled' => true]);

        $this->assertSame(
            'O ambiente fiscal não é homologação (fiscal.environment=producao).',
            resolve(SerproProbeGate::class)->refusalReason(),
        );
    }

    public function test_recusa_quando_flag_de_probe_desligada(): void
    {
        config(['fiscal.environment' => 'homologacao']);
        config(['serpro_probes.enabled' => false]);

        $this->assertStringContainsString('SERPRO_PROBE_ENABLED', (string) resolve(SerproProbeGate::class)->refusalReason());
    }

    public function test_permite_em_homologacao_com_flag_ligada(): void
    {
        config(['fiscal.environment' => 'homologacao']);
        config(['serpro_probes.enabled' => true]);

        $this->assertNull(resolve(SerproProbeGate::class)->refusalReason());
    }

    public function test_force_ignora_apenas_a_flag_enabled(): void
    {
        config(['fiscal.environment' => 'homologacao']);
        config(['serpro_probes.enabled' => false]);

        $this->assertNull(resolve(SerproProbeGate::class)->refusalReason(force: true));
    }
}
