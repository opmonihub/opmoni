<?php

namespace Tests\Feature\Serpro;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Services\SerproPgdasHomologationProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Probe PGDAS real em homologação (grupo serpro-trial). Pula quando credencial,
 * termo ou canário local não existem — alinhado ao trial contract.
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

        $report = resolve(SerproPgdasHomologationProbe::class)->run();

        $this->assertSame('skip', $report['outcome']);
        $this->assertSame('conta_nao_encontrada', $report['reason']);
        Http::assertNothingSent();
    }

    public function test_homologacao_real_ou_skip_justificado(): void
    {
        if (SerproConnection::current() === null || ! SerproConnection::current()->isConfigured()) {
            $this->markTestSkipped('Conexão SERPRO real não configurada neste ambiente.');
        }

        if (config('fiscal.environment') !== 'homologacao') {
            $this->markTestSkipped('FISCAL_ENVIRONMENT não é homologacao.');
        }

        $account = $this->contaCanario();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '30288513000100',
        ]);

        SerproClientAuthorization::forceCreate([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00146',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => now()->addYear(),
        ]);

        $report = resolve(SerproPgdasHomologationProbe::class)->run($account->getKey(), '30288513000100');

        $this->assertContains($report['outcome'], ['pass', 'skip'], json_encode($report, JSON_THROW_ON_ERROR));

        if ($report['outcome'] === 'pass') {
            $this->assertArrayHasKey('projecao', $report['steps']);
            $this->assertArrayHasKey('projection', $report);
        }
    }

    private function contaCanario(): Account
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '48123272000105',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        return $account;
    }
}
