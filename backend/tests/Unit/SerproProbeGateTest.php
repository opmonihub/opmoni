<?php

namespace Tests\Unit;

use App\Services\SerproProbeGate;
use Tests\TestCase;

class SerproProbeGateTest extends TestCase
{
    public function test_recusa_quando_ambiente_e_producao(): void
    {
        config(['fiscal.environment' => 'producao', 'serpro_probes.enabled' => true]);

        $gate = resolve(SerproProbeGate::class);

        $this->assertSame('ambiente_nao_homologacao', $gate->blockReason());
        $this->assertStringContainsString('homologacao', $gate->humanMessage('ambiente_nao_homologacao'));
    }

    public function test_recusa_quando_flag_desligada_sem_force(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => false]);

        $gate = resolve(SerproProbeGate::class);

        $this->assertSame('probe_desligado', $gate->blockReason());
        $this->assertStringContainsString('SERPRO_PROBE_ENABLED', $gate->humanMessage('probe_desligado'));
    }

    public function test_force_ignora_flag_mas_nao_o_ambiente(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => false]);

        $this->assertNull(resolve(SerproProbeGate::class)->blockReason(true));
    }

    public function test_permite_em_homologacao_com_flag_ligada(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => true]);

        $this->assertNull(resolve(SerproProbeGate::class)->blockReason());
    }
}
