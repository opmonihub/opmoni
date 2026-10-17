<?php

namespace Tests\Unit;

use App\Services\Fiscal\Manifestacao\FiscalScienceRolloutGate;
use Tests\TestCase;

class FiscalScienceRolloutGateTest extends TestCase
{
    private FiscalScienceRolloutGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new FiscalScienceRolloutGate;
    }

    public function test_rollout_defaults_remain_disabled_without_global_gate(): void
    {
        config([
            'fiscal.manifestacao_enabled' => false,
            'fiscal.manifestacao_rollout_mode' => 'disabled',
            'fiscal.manifestacao_pilot_allowlist' => [],
        ]);

        $result = $this->gate->evaluate(1, 2, str_repeat('1', 44));

        $this->assertFalse($result['allowed']);
        $this->assertSame('gate_disabled', $result['reason']);
    }

    public function test_pilot_mode_blocks_when_allowlist_is_empty(): void
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'pilot',
            'fiscal.manifestacao_pilot_allowlist' => [],
        ]);

        $result = $this->gate->evaluate(1, 2, str_repeat('2', 44));

        $this->assertFalse($result['allowed']);
        $this->assertSame('pilot_scope', $result['reason']);
    }

    public function test_pilot_mode_allows_only_matching_tuple(): void
    {
        $chave = str_repeat('3', 44);

        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'pilot',
            'fiscal.manifestacao_pilot_allowlist' => [[10, 20, $chave]],
        ]);

        $allowed = $this->gate->evaluate(10, 20, $chave);
        $outsideClient = $this->gate->evaluate(10, 99, $chave);
        $outsideKey = $this->gate->evaluate(10, 20, str_repeat('4', 44));

        $this->assertTrue($allowed['allowed']);
        $this->assertNull($allowed['reason']);
        $this->assertFalse($outsideClient['allowed']);
        $this->assertSame('pilot_scope', $outsideClient['reason']);
        $this->assertFalse($outsideKey['allowed']);
        $this->assertSame('pilot_scope', $outsideKey['reason']);
    }

    public function test_clearing_pilot_allowlist_does_not_imply_fleet(): void
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'pilot',
            'fiscal.manifestacao_pilot_allowlist' => [],
        ]);

        $result = $this->gate->evaluate(1, 2, str_repeat('5', 44));

        $this->assertFalse($result['allowed']);
        $this->assertSame('pilot_scope', $result['reason']);
    }

    public function test_fleet_mode_requires_explicit_selection(): void
    {
        config([
            'fiscal.manifestacao_enabled' => true,
            'fiscal.manifestacao_rollout_mode' => 'fleet',
            'fiscal.manifestacao_pilot_allowlist' => [],
        ]);

        $result = $this->gate->evaluate(1, 2, str_repeat('6', 44));

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }
}
