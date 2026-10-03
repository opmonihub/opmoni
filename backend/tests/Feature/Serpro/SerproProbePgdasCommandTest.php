<?php

namespace Tests\Feature\Serpro;

use App\Console\Commands\SerproProbePgdas;
use App\Services\SerproPgdasHomologationProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gate e saída do `serpro:probe-pgdas` — sem rede (`Http::preventStrayRequests`).
 */
class SerproProbePgdasCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_recusa_producao_antes_de_qualquer_http(): void
    {
        config(['fiscal.environment' => 'producao', 'serpro_probes.enabled' => true]);

        Http::fake(['*' => Http::response('{}', 200)]);

        $this->artisan('serpro:probe-pgdas', ['--force' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_recusa_probe_desligado_sem_force(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => false]);

        $this->artisan('serpro:probe-pgdas')->assertExitCode(1);
    }

    public function test_sem_credencial_resulta_em_skip_legivel(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => true]);

        $this->artisan('serpro:probe-pgdas', ['--force' => true])
            ->expectsOutputToContain('skip')
            ->assertExitCode(SerproProbePgdas::SKIP_EXIT);
    }

    public function test_caminho_feliz_imprime_etapas(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => true]);

        $this->mock(SerproPgdasHomologationProbe::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn([
                'outcome' => 'pass',
                'reason' => null,
                'account_id' => 1,
                'client_id' => 2,
                'steps' => [
                    'auth' => ['status' => 'pass', 'detail' => 'ok'],
                    'consulta' => ['status' => 'pass', 'detail' => 'ok'],
                    'projecao' => ['status' => 'pass', 'detail' => '1 período(s).'],
                ],
                'projection' => ['periods' => [['period' => '2026-01']]],
            ]);
        });

        $this->artisan('serpro:probe-pgdas', ['--force' => true])
            ->expectsOutputToContain('outcome: pass')
            ->assertExitCode(0);
    }

    public function test_throttle_simulado_retorna_skip(): void
    {
        config(['fiscal.environment' => 'homologacao', 'serpro_probes.enabled' => true]);

        $this->mock(SerproPgdasHomologationProbe::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn([
                'outcome' => 'skip',
                'reason' => 'provedor',
                'account_id' => 1,
                'client_id' => 2,
                'steps' => [
                    'consulta' => ['status' => 'skip', 'detail' => '[900807] limitado'],
                ],
            ]);
        });

        $this->artisan('serpro:probe-pgdas', ['--force' => true])
            ->assertExitCode(SerproProbePgdas::SKIP_EXIT);
    }
}
