<?php

namespace Tests\Feature\Fiscal;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Probe da ADN NFS-e contra o serviço real — opt-in (`nfse-live`), fora da
 * suíte padrão como o `serpro-trial`.
 *
 * Roda só quando alguém preparou a máquina: `FISCAL_NFSE_ENABLED=true`, um
 * `fiscal.environment` nomeado e um cliente capturável passado por
 * `NFSE_LIVE_CLIENT`. Sem os três, o teste pula — é canário manual, não CI.
 */
#[Group('nfse-live')]
class NfseAdnLiveProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_real_quando_a_instalacao_esta_preparada(): void
    {
        if (! config('fiscal.nfse_enabled', false)) {
            $this->markTestSkipped('FISCAL_NFSE_ENABLED não está ligado.');
        }

        $clientId = (int) env('NFSE_LIVE_CLIENT', 0);

        if ($clientId <= 0) {
            $this->markTestSkipped('NFSE_LIVE_CLIENT não aponta um cliente.');
        }

        $client = Client::query()->capturable()->whereKey($clientId)->first();

        if ($client === null) {
            $this->markTestSkipped("Cliente {$clientId} inexistente ou não capturável neste banco.");
        }

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) $client->getKey()])
            ->assertExitCode(0);
    }
}
