<?php

namespace Tests\Unit;

use App\Services\SerproProbeGate;
use Tests\TestCase;

class SerproProbeGateTest extends TestCase
{
    public function test_recusa_quando_ambiente_e_producao(): void
    {
        config(['fiscal.environment' => 'producao', 'serpro_probes.enabled' => true]);

        $this->assertSame('ambiente_nao_homologacao', resolve(SerproProbeGate::class)->blockReason());
    }

    public function test_recusa_quando_flag_desligada_sem_force(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => false]);

        $this->assertSame('probe_desligado', resolve(SerproProbeGate::class)->blockReason());
    }

    public function test_force_ignora_flag_mas_nao_o_ambiente(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => false]);

        $this->assertNull(resolve(SerproProbeGate::class)->blockReason(true));
    }
}
